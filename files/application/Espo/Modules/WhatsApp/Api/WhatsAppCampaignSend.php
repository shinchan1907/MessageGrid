<?php
namespace Espo\Modules\WhatsApp\Api;

use Espo\Core\Api\Action;
use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\Api\ResponseComposer;
use Espo\ORM\EntityManager;
use Espo\Core\Config;
use Espo\Modules\WhatsApp\Services\QueueService;
use Espo\Modules\WhatsApp\Services\MetaApiService;
use Espo\Modules\WhatsApp\Services\StorageService;
use Exception;

class WhatsAppCampaignSend implements Action
{
    public function __construct(
        private EntityManager $entityManager,
        private Config $config
    ) {}

    public function process(Request $request, Response $response): void
    {
        $data = $request->getParsedBody();
        if (!is_array($data)) {
            $data = json_decode($request->getBody()->getContents(), true) ?: [];
        }

        $name = $data['name'] ?? '';
        $templateId = $data['templateId'] ?? '';
        $segmentType = $data['segmentType'] ?? 'Lead'; // Lead or Contact
        $filters = $data['filters'] ?? [];

        if (empty($name) || empty($templateId)) {
            $response->setStatus(400);
            $response->setBody(ResponseComposer::json(['error' => 'Missing campaign name or template ID.']));
            return;
        }

        try {
            // 1. Create Campaign Entity
            $campaign = $this->entityManager->createEntity('WhatsAppCampaign');
            $campaign->set([
                'name' => $name,
                'templateId' => $templateId,
                'segmentFilters' => json_encode($filters),
                'status' => 'Queued',
                'createdAt' => date('Y-m-d H:i:s'),
                'modifiedAt' => date('Y-m-d H:i:s')
            ]);
            $this->entityManager->saveEntity($campaign);

            // 2. Fetch Matching CRM Records dynamically
            $crmQuery = $this->entityManager->getRepository($segmentType);
            $where = [];

            // Apply smart segments based on key-value filter parameters
            if (!empty($filters['city'])) {
                $where['addressCity*='] = $filters['city'];
            }
            if (!empty($filters['leadSource']) && $segmentType === 'Lead') {
                $where['leadSource'] = $filters['leadSource'];
            }
            if (!empty($filters['status']) && $segmentType === 'Lead') {
                $where['status'] = $filters['status'];
            }
            if (!empty($filters['tag'])) {
                $where['tags*='] = $filters['tag'];
            }

            // Exclude empty phone numbers
            $where['phoneNumber!='] = '';

            $crmRecords = $crmQuery->where($where)->find();
            $totalCount = 0;

            // 3. Populate Campaign Members
            foreach ($crmRecords as $record) {
                $phone = $record->get('phoneNumber') ?: $record->get('phoneNumberMobile') ?: $record->get('phoneNumberWork');
                $phoneClean = preg_replace('/[^0-9]/', '', $phone);
                
                if (empty($phoneClean)) {
                    continue;
                }

                // Prevent duplicates in same campaign
                $existingMember = $this->entityManager->getRepository('WhatsAppCampaignMember')
                    ->where([
                        'campaignId' => $campaign->id,
                        'phoneNumber' => $phoneClean
                    ])
                    ->findOne();

                if ($existingMember) {
                    continue;
                }

                $member = $this->entityManager->createEntity('WhatsAppCampaignMember');
                $member->set([
                    'campaignId' => $campaign->id,
                    'entityType' => $segmentType,
                    'entityId' => $record->id,
                    'phoneNumber' => $phoneClean,
                    'status' => 'Queued',
                    'createdAt' => date('Y-m-d H:i:s')
                ]);
                $this->entityManager->saveEntity($member);
                $totalCount++;
            }

            // 4. Update Campaign Counts
            $campaign->set('totalCount', $totalCount);
            if ($totalCount === 0) {
                $campaign->set('status', 'Completed');
            }
            $this->entityManager->saveEntity($campaign);

            // 5. Enqueue Async Queue Processor Job
            if ($totalCount > 0) {
                $queue = new QueueService($this->entityManager, $this->config, new MetaApiService($this->config), new StorageService($this->config));
                $queue->push('CampaignBroadcast', ['campaignId' => $campaign->id]);
            }

            $response->setBody(ResponseComposer::json([
                'success' => true,
                'campaignId' => $campaign->id,
                'totalCount' => $totalCount
            ]));

        } catch (Exception $e) {
            $response->setStatus(400);
            $response->setBody(ResponseComposer::json(['error' => $e->getMessage()]));
        }
    }
}
