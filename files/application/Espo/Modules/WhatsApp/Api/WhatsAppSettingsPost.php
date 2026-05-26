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

class WhatsAppSettingsPost implements Action
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

        $service->saveSettings($data);

        $response->setBody(ResponseComposer::json(['success' => true]));
    }
}
