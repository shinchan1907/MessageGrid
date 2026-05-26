<?php
namespace Espo\Modules\WhatsApp\Api;

use Espo\Core\Api\Action;
use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\Api\ResponseComposer;
use Espo\ORM\EntityManager;
use Espo\Core\Config;
use Espo\Modules\WhatsApp\Services\WhatsAppService;
use Espo\Modules\WhatsApp\Services\MetaApiService;
use Espo\Modules\WhatsApp\Services\StorageService;
use Exception;

class WhatsAppSendMessage implements Action
{
    public function __construct(
        private EntityManager $entityManager,
        private Config $config
    ) {}

    public function process(Request $request, Response $response): void
    {
        $service = new WhatsAppService(
            $this->entityManager,
            $this->config,
            new MetaApiService($this->config),
            new StorageService($this->config)
        );

        $data = $request->getParsedBody();
        if (!is_array($data)) {
            $data = json_decode($request->getBody()->getContents(), true) ?: [];
        }

        $conversationId = $data['conversationId'] ?? '';
        $agentId = $data['agentId'] ?? '';
        $type = $data['type'] ?? 'text';
        $content = $data['content'] ?? [];

        if (empty($conversationId) || empty($agentId)) {
            $response->setStatus(400);
            $response->setBody(ResponseComposer::json(['error' => 'Missing conversationId or agentId parameters.']));
            return;
        }

        try {
            $result = $service->sendChatMessage($conversationId, $agentId, strtolower($type), $content);
            $response->setBody(ResponseComposer::json($result));
        } catch (Exception $e) {
            $response->setStatus(400);
            $response->setBody(ResponseComposer::json(['error' => $e->getMessage()]));
        }
    }
}
