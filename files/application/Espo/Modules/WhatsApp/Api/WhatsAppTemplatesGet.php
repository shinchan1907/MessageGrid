<?php
namespace Espo\Modules\WhatsApp\Api;

use Espo\Core\Api\Action;
use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\Api\ResponseComposer;
use Espo\ORM\EntityManager;
use Espo\Core\Config;

class WhatsAppTemplatesGet implements Action
{
    public function __construct(
        private EntityManager $entityManager,
        private Config $config
    ) {}

    public function process(Request $request, Response $response): void
    {
        $templates = $this->entityManager->getRepository('WhatsAppTemplate')
            ->where(['status' => 'Approved'])
            ->order('name', 'ASC')
            ->find();

        $output = [];
        foreach ($templates as $tmpl) {
            $output[] = $tmpl->toArray();
        }

        $response->setBody(ResponseComposer::json($output));
    }
}
