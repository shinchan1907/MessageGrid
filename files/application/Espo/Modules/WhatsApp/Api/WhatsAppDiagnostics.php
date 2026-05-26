<?php
namespace Espo\Modules\WhatsApp\Api;

use Espo\Core\Api\Action;
use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\Api\ResponseComposer;
use Espo\ORM\EntityManager;
use Espo\Core\Config;
use Exception;

class WhatsAppDiagnostics implements Action
{
    public function __construct(
        private EntityManager $entityManager,
        private Config $config
    ) {}

    public function process(Request $request, Response $response): void
    {
        $format = $request->getQueryParams()['format'] ?? 'json';

        // Retrieve diagnostic metrics
        $metrics = $this->gatherTelemetry();

        if ($format === 'prometheus') {
            $response->setHeader('Content-Type', 'text/plain; version=0.0.4');
            $response->setBody($this->convertToPrometheus($metrics));
            return;
        }

        $response->setBody(ResponseComposer::json($metrics));
    }

    private function gatherTelemetry(): array
    {
        $telemetry = [];

        // 1. Database Queue Metrics
        try {
            $queueRepo = $this->entityManager->getRepository('WhatsAppQueueJob');
            $telemetry['queue_pending_count'] = $queueRepo->where(['status' => 'Pending'])->count();
            $telemetry['queue_failed_count'] = $queueRepo->where(['status' => 'Failed'])->count();
            $telemetry['queue_completed_count'] = $queueRepo->where(['status' => 'Completed'])->count();
        } catch (Exception) {
            $telemetry['queue_pending_count'] = 0;
            $telemetry['queue_failed_count'] = 0;
            $telemetry['queue_completed_count'] = 0;
        }

        // 2. Active Conversations & Messaging counts
        try {
            $convRepo = $this->entityManager->getRepository('WhatsAppConversation');
            $msgRepo = $this->entityManager->getRepository('WhatsAppMessage');

            $telemetry['total_conversations'] = $convRepo->count();
            $telemetry['active_conversations'] = $convRepo->where(['status' => 'Open'])->count();
            $telemetry['total_messages'] = $msgRepo->count();
        } catch (Exception) {
            $telemetry['total_conversations'] = 0;
            $telemetry['active_conversations'] = 0;
            $telemetry['total_messages'] = 0;
        }

        // 3. Infrastructure and System Telemetry
        $telemetry['system_memory_used_bytes'] = memory_get_usage(true);
        $telemetry['system_memory_limit_bytes'] = $this->parseIniSize(ini_get('memory_limit'));
        
        $freeDisk = disk_free_space(dirname(__DIR__));
        $totalDisk = disk_total_space(dirname(__DIR__));
        $telemetry['system_disk_free_bytes'] = $freeDisk !== false ? $freeDisk : 0;
        $telemetry['system_disk_total_bytes'] = $totalDisk !== false ? $totalDisk : 0;

        // 4. Redis Connectivity Verification
        $telemetry['redis_status'] = 'disabled';
        if ($this->config->get('cacheBackend') === 'Redis') {
            try {
                $redisHost = $this->config->get('redisHost') ?? '127.0.0.1';
                $redisPort = $this->config->get('redisPort') ?? 6379;
                
                $socket = @fsockopen($redisHost, $redisPort, $errno, $errstr, 1.5);
                if ($socket) {
                    $telemetry['redis_status'] = 'connected';
                    fclose($socket);
                } else {
                    $telemetry['redis_status'] = 'offline';
                }
            } catch (Exception) {
                $telemetry['redis_status'] = 'error';
            }
        }

        // 5. Meta API Health Verification
        $telemetry['meta_api_health'] = 'configured';
        $wabaId = $this->config->get('whatsappWabaId');
        $phoneId = $this->config->get('whatsappPhoneId');
        if (empty($wabaId) || empty($phoneId)) {
            $telemetry['meta_api_health'] = 'unconfigured';
        }

        return $telemetry;
    }

    private function convertToPrometheus(array $metrics): string
    {
        $lines = [];
        $lines[] = "# HELP espo_whatsapp_queue_pending_count Number of jobs currently pending in the queue";
        $lines[] = "# TYPE espo_whatsapp_queue_pending_count gauge";
        $lines[] = "espo_whatsapp_queue_pending_count " . $metrics['queue_pending_count'];

        $lines[] = "# HELP espo_whatsapp_queue_failed_count Number of jobs currently failed in the queue";
        $lines[] = "# TYPE espo_whatsapp_queue_failed_count gauge";
        $lines[] = "espo_whatsapp_queue_failed_count " . $metrics['queue_failed_count'];

        $lines[] = "# HELP espo_whatsapp_conversations_total Total active WhatsApp conversation count";
        $lines[] = "# TYPE espo_whatsapp_conversations_total gauge";
        $lines[] = "espo_whatsapp_conversations_total " . $metrics['total_conversations'];

        $lines[] = "# HELP espo_whatsapp_system_memory_used_bytes PHP active worker memory utilization";
        $lines[] = "# TYPE espo_whatsapp_system_memory_used_bytes gauge";
        $lines[] = "espo_whatsapp_system_memory_used_bytes " . $metrics['system_memory_used_bytes'];

        return implode("\n", $lines) . "\n";
    }

    private function parseIniSize(string $val): int
    {
        $val = trim($val);
        if (empty($val) || $val === '-1') {
            return -1;
        }
        $last = strtolower($val[strlen($val) - 1]);
        $val = (int)$val;
        switch ($last) {
            case 'g': $val *= 1024;
            case 'm': $val *= 1024;
            case 'k': $val *= 1024;
        }
        return $val;
    }
}
