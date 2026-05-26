<?php
namespace Espo\Modules\WhatsApp\EntryPoints;

use Espo\Core\EntryPoint\EntryPoint;
use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\EntryPoint\Traits\NoAuth;
use Espo\Core\Config;
use Espo\ORM\EntityManager;
use Espo\Modules\WhatsApp\Services\MetaApiService;
use Espo\Modules\WhatsApp\Services\QueueService;
use Exception;

class WhatsAppWebhook implements EntryPoint
{
    use NoAuth;

    public function __construct(
        private EntityManager $entityManager,
        private Config $config
    ) {}

    public function run(Request $request, Response $response): void
    {
        $metaApiService = new MetaApiService($this->config);
        $queueService = new QueueService($this->entityManager, $this->config, $metaApiService, new \Espo\Modules\WhatsApp\Services\StorageService($this->config));

        $method = $request->getMethod();

        // 1. Meta Webhook Handshake Verification (GET)
        if ($method === 'GET') {
            $params = $request->getQueryParams();
            $mode = $params['hub_mode'] ?? '';
            $token = $params['hub_verify_token'] ?? '';
            $challenge = $params['hub_challenge'] ?? '';

            if ($mode === 'subscribe' && !empty($token) && !empty($challenge)) {
                try {
                    $verifiedChallenge = $metaApiService->verifyWebhook($token, $challenge);
                    $response->write($verifiedChallenge);
                    return;
                } catch (Exception $e) {
                    $response->setStatus(403);
                    $response->write($e->getMessage());
                    return;
                }
            }
            $response->setStatus(400);
            $response->write("Invalid handshake parameters.");
            return;
        }

        // 2. Incoming Webhook Event Processing (POST)
        if ($method === 'POST') {
            // Get raw body for cryptographic signature verification
            $rawBody = $request->getBody()->getContents();
            $signatureHeader = $request->getHeaderLine('X-Hub-Signature-256');

            // Secure Webhook signature validation
            if (!$metaApiService->validateSignature($rawBody, $signatureHeader)) {
                $response->setStatus(403);
                $response->write("Signature verification failed.");
                return;
            }

            $payload = json_decode($rawBody, true) ?: [];

            // Save in diagnostic Webhook Logs
            $log = $this->entityManager->createEntity('WhatsAppWebhookLog');
            $log->set([
                'direction' => 'Incoming',
                'payload' => $rawBody,
                'statusCode' => 200,
                'status' => 'Success',
                'createdAt' => date('Y-m-d H:i:s')
            ]);
            $this->entityManager->saveEntity($log);

            try {
                // Instantly offload payload parsing to background queue runner to avoid web request blocking
                $queueService->push('WebhookProcessor', $payload);
                
                $response->setStatus(200);
                $response->write(json_encode(['status' => 'queued']));
            } catch (Exception $e) {
                $log->set([
                    'status' => 'Error',
                    'errorMessage' => $e->getMessage()
                ]);
                $this->entityManager->saveEntity($log);

                $response->setStatus(500);
                $response->write(json_encode(['status' => 'error', 'message' => $e->getMessage()]));
            }
            return;
        }

        $response->setStatus(405);
        $response->write("Method not allowed.");
    }
}
