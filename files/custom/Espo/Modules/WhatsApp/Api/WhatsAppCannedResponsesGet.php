<?php
namespace Espo\Modules\WhatsApp\Api;

use Espo\Core\Api\Action;
use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\Api\ResponseComposer;
use Espo\ORM\EntityManager;
use Espo\Core\Config;

class WhatsAppCannedResponsesGet implements Action
{
    public function __construct(
        private EntityManager $entityManager,
        private Config $config
    ) {}

    public function process(Request $request, Response $response): void
    {
        $canned = $this->entityManager->getRepository('WhatsAppCannedResponse')
            ->order('shortcut', 'ASC')
            ->find();

        $output = [];
        foreach ($canned as $item) {
            $output[] = $item->toArray();
        }

        $response->setBody(ResponseComposer::json($output));
    }
}
