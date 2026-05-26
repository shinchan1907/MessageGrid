<?php
namespace Espo\Modules\WhatsApp\Api;

use Espo\Core\Api\Action;
use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\Api\ResponseComposer;
use Espo\ORM\EntityManager;
use Espo\Core\Config;

class WhatsAppCampaignsGet implements Action
{
    public function __construct(
        private EntityManager $entityManager,
        private Config $config
    ) {}

    public function process(Request $request, Response $response): void
    {
        $campaigns = $this->entityManager->getRepository('WhatsAppCampaign')
            ->order('createdAt', 'DESC')
            ->find();

        $output = [];
        foreach ($campaigns as $camp) {
            $data = $camp->toArray();

            // Translate template details
            if (!empty($camp->get('templateId'))) {
                $tmpl = $this->entityManager->getEntity('WhatsAppTemplate', $camp->get('templateId'));
                if ($tmpl) {
                    $data['templateName'] = $tmpl->get('name');
                }
            }

            $output[] = $data;
        }

        $response->setBody(ResponseComposer::json($output));
    }
}
