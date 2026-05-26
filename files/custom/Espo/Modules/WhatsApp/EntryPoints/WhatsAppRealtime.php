<?php
namespace Espo\Modules\WhatsApp\EntryPoints;

use Espo\Core\EntryPoint\EntryPoint;
use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\ORM\EntityManager;
use Espo\Core\Config;

class WhatsAppRealtime implements EntryPoint
{
    // NO NoAuth trait is used. This enforces active EspoCRM session authentication.

    public function __construct(
        private EntityManager $entityManager,
        private Config $config
    ) {}

    public function run(Request $request, Response $response): void
    {
        // 1. Disable PHP execution timeout limit for long-lived connection
        @set_time_limit(0);

        // 2. Disable output buffering and Gzip compression to support direct flushing
        @ini_set('zlib.output_compression', 'Off');
        @ini_set('output_buffering', 'Off');
        @ini_set('output_handler', '');
        if (function_exists('apache_setenv')) {
            @apache_setenv('no-gzip', '1');
        }

        // 3. Emit standard SSE Headers
        $response->setHeader('Content-Type', 'text/event-stream');
        $response->setHeader('Cache-Control', 'no-cache');
        $response->setHeader('Connection', 'keep-alive');
        $response->setHeader('X-Accel-Buffering', 'no'); // Prevent buffering on Nginx reverse proxies

        // 4. Retrieve query arguments
        $params = $request->getQueryParams();
        $conversationId = $params['conversationId'] ?? '';
        
        $lastCheckedTime = date('Y-m-d H:i:s');

        // Loop limit of 25 seconds to prevent PHP resource exhaustion and trigger structured client reconnection
        $secondsLimit = 25;
        $startTime = time();

        while (time() - $startTime < $secondsLimit) {
            // Terminate loop early if client severed connection
            if (connection_aborted()) {
                break;
            }

            $hasUpdate = false;
            $eventPayload = [];

            // Case A: Detect any incoming or outgoing conversation modifications
            $convRepo = $this->entityManager->getRepository('WhatsAppConversation');
            $updatedConvs = $convRepo->where([
                'lastMessageTime>=' => $lastCheckedTime
            ])->find();

            if (count($updatedConvs) > 0) {
                $hasUpdate = true;
                $eventPayload['conversations'] = true;
            }

            // Case B: Detect new messages under selected conversation
            if (!empty($conversationId)) {
                $msgRepo = $this->entityManager->getRepository('WhatsAppMessage');
                $newMsgs = $msgRepo->where([
                    'conversationId' => $conversationId,
                    'createdAt>=' => $lastCheckedTime
                ])->find();

                if (count($newMsgs) > 0) {
                    $hasUpdate = true;
                    $eventPayload['messages'] = true;
                }
            }

            // Flush data instantly
            if ($hasUpdate) {
                $response->write("data: " . json_encode($eventPayload) . "\n\n");
                @ob_flush();
                @flush();
                // Move checkpoint forward
                $lastCheckedTime = date('Y-m-d H:i:s');
            } else {
                // Heartbeat to prevent connection drop timeouts
                $response->write(": heartbeat\n\n");
                @ob_flush();
                @flush();
            }

            sleep(2);
        }
    }
}
