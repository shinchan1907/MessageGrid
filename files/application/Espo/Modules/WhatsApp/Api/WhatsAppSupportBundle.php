<?php
namespace Espo\Modules\WhatsApp\Api;

use Espo\Core\Api\Action;
use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\Api\ResponseComposer;
use Espo\ORM\EntityManager;
use Espo\Core\Config;
use Exception;

class WhatsAppSupportBundle implements Action
{
    public function __construct(
        private EntityManager $entityManager,
        private Config $config
    ) {}

    public function process(Request $request, Response $response): void
    {
        try {
            $bundle = [];

            // 1. Gather System details
            $bundle['system_info'] = [
                'php_version' => PHP_VERSION,
                'os' => PHP_OS,
                'crm_cache_backend' => $this->config->get('cacheBackend') ?? 'file',
                'crm_timezone' => $this->config->get('timezone') ?? 'UTC',
                'crypt_key_configured' => !empty($this->config->get('cryptKey')),
            ];

            // 2. Fetch Masked settings
            $bundle['settings_mask'] = [
                'waba_id' => $this->maskString($this->config->get('whatsappWabaId')),
                'phone_id' => $this->maskString($this->config->get('whatsappPhoneId')),
                'meta_app_id' => $this->maskString($this->config->get('whatsappMetaAppId')),
                'verify_token_set' => !empty($this->config->get('whatsappMetaVerifyToken')),
                's3_bucket' => $this->config->get('whatsappS3Bucket') ?? 'not_set',
                's3_region' => $this->config->get('whatsappS3Region') ?? 'not_set',
            ];

            // 3. Last 50 entries of Webhook callback logs
            try {
                $logsRepo = $this->entityManager->getRepository('WhatsAppWebhookLog');
                $logs = $logsRepo->limit(50)->order('createdAt', 'DESC')->find();
                
                $bundle['recent_webhook_logs'] = [];
                foreach ($logs as $log) {
                    $bundle['recent_webhook_logs'][] = [
                        'id' => $log->id,
                        'status' => $log->get('status'),
                        'errorMessage' => $log->get('errorMessage'),
                        'createdAt' => $log->get('createdAt')
                    ];
                }
            } catch (Exception) {
                $bundle['recent_webhook_logs'] = ['error' => 'Could not query webhook logs.'];
            }

            // 4. Queue distribution statistics
            try {
                $queueRepo = $this->entityManager->getRepository('WhatsAppQueueJob');
                $bundle['queue_stats'] = [
                    'pending' => $queueRepo->where(['status' => 'Pending'])->count(),
                    'failed' => $queueRepo->where(['status' => 'Failed'])->count(),
                    'completed' => $queueRepo->where(['status' => 'Completed'])->count(),
                ];
            } catch (Exception) {
                $bundle['queue_stats'] = ['error' => 'Could not query queue stats.'];
            }

            $response->setHeader('Content-Type', 'application/json');
            $response->setHeader('Content-Disposition', 'attachment; filename="waba_support_bundle_' . date('Ymd_His') . '.json"');
            
            $response->setBody(json_encode($bundle, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        } catch (Exception $e) {
            $response->setStatus(500);
            $response->setBody(ResponseComposer::json(['error' => $e->getMessage()]));
        }
    }

    private function maskString(?string $str): string
    {
        if (empty($str)) {
            return 'empty';
        }
        $len = strlen($str);
        if ($len <= 6) {
            return '******';
        }
        return substr($str, 0, 3) . str_repeat('*', $len - 6) . substr($str, -3);
    }
}
