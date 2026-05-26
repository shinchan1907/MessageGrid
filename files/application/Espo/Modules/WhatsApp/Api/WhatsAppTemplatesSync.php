<?php
namespace Espo\Modules\WhatsApp\Api;

use Espo\Core\Api\Action;
use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\Api\ResponseComposer;
use Espo\ORM\EntityManager;
use Espo\Core\Config;
use Espo\Modules\WhatsApp\Services\MetaApiService;
use Exception;

class WhatsAppTemplatesSync implements Action
{
    public function __construct(
        private EntityManager $entityManager,
        private Config $config
    ) {}

    public function process(Request $request, Response $response): void
    {
        $metaApi = new MetaApiService($this->config);

        try {
            $metaTemplates = $metaApi->syncTemplates();
            $count = 0;

            foreach ($metaTemplates as $tmplData) {
                $name = $tmplData['name'] ?? '';
                $lang = $tmplData['language'] ?? 'en';
                
                if (empty($name)) {
                    continue;
                }

                // Check duplicate
                $existing = $this->entityManager->getRepository('WhatsAppTemplate')
                    ->where([
                        'name' => $name,
                        'language' => $lang
                    ])
                    ->findOne();

                if (!$existing) {
                    $existing = $this->entityManager->createEntity('WhatsAppTemplate');
                    $existing->set('createdAt', date('Y-m-d H:i:s'));
                }

                // Map Meta categories and statuses to uppercase enums
                $category = ucfirst(strtolower($tmplData['category'] ?? 'marketing'));
                if ($category === 'Utility') {
                    $category = 'Utility';
                } elseif ($category === 'Authentication') {
                    $category = 'Authentication';
                } else {
                    $category = 'Marketing';
                }

                $status = ucfirst(strtolower($tmplData['status'] ?? 'approved'));
                if (!in_array($status, ['Approved', 'Pending', 'Rejected', 'Deleted'])) {
                    $status = 'Approved';
                }

                $existing->set([
                    'name' => $name,
                    'language' => $lang,
                    'category' => $category,
                    'status' => $status,
                    'components' => json_encode($tmplData['components'] ?? []),
                    'modifiedAt' => date('Y-m-d H:i:s')
                ]);

                $this->entityManager->saveEntity($existing);
                $count++;
            }

            $response->setBody(ResponseComposer::json([
                'success' => true,
                'count' => $count
            ]));
        } catch (Exception $e) {
            $response->setStatus(400);
            $response->setBody(ResponseComposer::json([
                'success' => false,
                'error' => $e->getMessage()
            ]));
        }
    }
}
