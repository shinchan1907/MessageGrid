<?php
namespace Espo\Modules\WhatsApp\Services;

use Espo\ORM\EntityManager;
use Espo\Core\Config;
use DateTime;
use Exception;

class QueueService
{
    public function __construct(
        private EntityManager $entityManager,
        private Config $config,
        private MetaApiService $metaApiService,
        private StorageService $storageService
    ) {}

    /**
     * Enqueue a new background job.
     */
    public function push(string $jobType, array $payload, ?DateTime $scheduledAt = null, int $maxAttempts = 3): string
    {
        $job = $this->entityManager->getEntity('WhatsAppQueueJob');
        if (!$job) {
            // Fallback dynamically if rebuild hasn't executed
            $job = $this->entityManager->createEntity('WhatsAppQueueJob');
        }
        
        $job->set([
            'jobType' => $jobType,
            'payload' => json_encode($payload),
            'status' => 'Pending',
            'attempts' => 0,
            'maxAttempts' => $maxAttempts,
            'scheduledAt' => ($scheduledAt ?? new DateTime())->format('Y-m-d H:i:s'),
            'createdAt' => date('Y-m-d H:i:s')
        ]);

        $this->entityManager->saveEntity($job);
        return $job->id;
    }

    /**
     * Process pending jobs in the queue.
     * Can be invoked via standard CRM Cron.
     */
    public function runQueue(int $limit = 20): int
    {
        $now = date('Y-m-d H:i:s');
        $jobs = $this->entityManager->getRepository('WhatsAppQueueJob')
            ->where([
                'status' => 'Pending',
                'scheduledAt<=' => $now
            ])
            ->limit($limit)
            ->order('scheduledAt', 'ASC')
            ->find();

        $processed = 0;
        foreach ($jobs as $job) {
            $job->set('status', 'Running');
            $job->set('attempts', $job->get('attempts') + 1);
            $this->entityManager->saveEntity($job);

            try {
                $payload = json_decode($job->get('payload'), true) ?: [];
                $this->executeJob($job->get('jobType'), $payload);

                $job->set('status', 'Success');
                $job->set('errorMessage', '');
                $this->entityManager->saveEntity($job);
            } catch (Exception $e) {
                $job->set('errorMessage', $e->getMessage());
                if ($job->get('attempts') >= $job->get('maxAttempts')) {
                    $job->set('status', 'Failed');
                } else {
                    $job->set('status', 'Pending');
                    // Retry in 2 minutes
                    $retryTime = new DateTime();
                    $retryTime->modify('+2 minutes');
                    $job->set('scheduledAt', $retryTime->format('Y-m-d H:i:s'));
                }
                $this->entityManager->saveEntity($job);
            }
            $processed++;
        }

        return $processed;
    }

    /**
     * Execute specific job type.
     */
    private function executeJob(string $jobType, array $payload): void
    {
        switch ($jobType) {
            case 'CampaignBroadcast':
                $this->executeCampaignBroadcast($payload);
                break;
            case 'WebhookProcessor':
                $this->executeWebhookProcessor($payload);
                break;
            case 'MediaDownloader':
                $this->executeMediaDownloader($payload);
                break;
            default:
                throw new Exception("Unsupported queue job type: {$jobType}");
        }
    }

    /**
     * Process a Campaign Broadcast step (sends a batch of template messages with rate limiting).
     */
    private function executeCampaignBroadcast(array $payload): void
    {
        $campaignId = $payload['campaignId'] ?? null;
        if (!$campaignId) {
            return;
        }

        $campaign = $this->entityManager->getEntity('WhatsAppCampaign', $campaignId);
        if (!$campaign || in_array($campaign->get('status'), ['Paused', 'Completed', 'Cancelled'])) {
            return;
        }

        // Lock campaign as sending
        $campaign->set('status', 'Sending');
        $this->entityManager->saveEntity($campaign);

        // Fetch queued members
        $members = $this->entityManager->getRepository('WhatsAppCampaignMember')
            ->where([
                'campaignId' => $campaignId,
                'status' => 'Queued'
            ])
            ->limit($campaign->get('rateLimit') ?: 60)
            ->find();

        if (count($members) === 0) {
            $campaign->set('status', 'Completed');
            $this->entityManager->saveEntity($campaign);
            return;
        }

        $template = $this->entityManager->getEntity('WhatsAppTemplate', $campaign->get('templateId'));
        if (!$template) {
            throw new Exception("Template not found for campaign broadcast.");
        }

        foreach ($members as $member) {
            try {
                // Map merge tags from CRM record (e.g. Lead, Contact)
                $crmRecord = $this->entityManager->getEntity($member->get('entityType'), $member->get('entityId'));
                $components = [];

                if ($crmRecord) {
                    $components = $this->parseTemplateVariables($template->get('components'), $crmRecord);
                }

                // Call API
                $res = $this->metaApiService->sendMessage(
                    $member->get('phoneNumber'),
                    'template',
                    [
                        'name' => $template->get('name'),
                        'language_code' => $template->get('language') ?: 'en',
                        'components' => $components
                    ]
                );

                $metaMsgId = $res['messages'][0]['id'] ?? 'campaign_' . uniqid();

                $member->set([
                    'status' => 'Sent',
                    'metaMessageId' => $metaMsgId,
                    'errorMessage' => ''
                ]);
                $this->entityManager->saveEntity($member);

                // Update Campaign Counters
                $campaign->set('sentCount', $campaign->get('sentCount') + 1);

                // Log as standard WhatsAppMessage
                $this->logCampaignMessageAsChat($campaignId, $member, $crmRecord, $template->get('name'), $metaMsgId);

            } catch (Exception $e) {
                $member->set([
                    'status' => 'Failed',
                    'errorMessage' => $e->getMessage()
                ]);
                $this->entityManager->saveEntity($member);

                $campaign->set('failedCount', $campaign->get('failedCount') + 1);
            }
        }

        $this->entityManager->saveEntity($campaign);

        // Re-queue campaign step if members remain
        $remaining = $this->entityManager->getRepository('WhatsAppCampaignMember')
            ->where([
                'campaignId' => $campaignId,
                'status' => 'Queued'
            ])
            ->count();

        if ($remaining > 0) {
            // Schedule next batch in 1 minute
            $nextRun = new DateTime();
            $nextRun->modify('+1 minute');
            $this->push('CampaignBroadcast', ['campaignId' => $campaignId], $nextRun);
        } else {
            $campaign->set('status', 'Completed');
            $this->entityManager->saveEntity($campaign);
        }
    }

    /**
     * Process a raw Webhook event payload asynchronously.
     */
    private function executeWebhookProcessor(array $payload): void
    {
        // This is handled by a custom handler in WhatsAppService to parse and map everything securely
        $service = new WhatsAppService($this->entityManager, $this->config, $this->metaApiService, $this->storageService, $this);
        $service->processWebhookPayload($payload);
    }

    /**
     * Process downloading heavy media files in the background.
     */
    private function executeMediaDownloader(array $payload): void
    {
        $messageId = $payload['messageId'] ?? null;
        $mediaId = $payload['mediaId'] ?? null;
        $mimeType = $payload['mimeType'] ?? null;
        $filename = $payload['filename'] ?? 'attachment';

        if (!$messageId || !$mediaId || !$mimeType) {
            return;
        }

        $message = $this->entityManager->getEntity('WhatsAppMessage', $messageId);
        if (!$message) {
            return;
        }

        // Fetch binary data from Meta
        $downloadRes = $this->metaApiService->downloadMedia($mediaId);
        
        // Write to storage
        $storedPath = $this->storageService->storeMedia(
            $downloadRes['binary'],
            $filename,
            $downloadRes['mime_type'] ?: $mimeType
        );

        // Update message reference
        $message->set([
            'mediaUrl' => $storedPath,
            'mediaSize' => $downloadRes['file_size']
        ]);

        $this->entityManager->saveEntity($message);
    }

    /**
     * Parse template body structures and replace variables (e.g. {{1}}, {{2}}) with actual CRM record properties.
     */
    private function parseTemplateVariables(string $componentsJson, $crmRecord): array
    {
        $components = json_decode($componentsJson, true) ?: [];
        $parsedComponents = [];

        foreach ($components as $component) {
            $type = strtolower($component['type'] ?? '');
            if ($type === 'body') {
                $parameters = [];
                // Analyze merge placeholders
                $text = $component['text'] ?? '';
                preg_match_all('/\{\{(\d+)\}\}/', $text, $matches);

                if (!empty($matches[1])) {
                    foreach ($matches[1] as $index) {
                        // Mappings map common index values to CRM fields dynamically
                        $value = "Client";
                        if ($index == 1) {
                            $value = $crmRecord->get('name') ?: $crmRecord->get('firstName') ?: "Client";
                        } elseif ($index == 2) {
                            $value = $crmRecord->get('email') ?: $crmRecord->get('emailAddress') ?: "";
                        } elseif ($index == 3) {
                            $value = $crmRecord->get('phoneNumber') ?: $crmRecord->get('phone') ?: "";
                        } else {
                            $value = "";
                        }

                        $parameters[] = [
                            "type" => "text",
                            "text" => (string)$value
                        ];
                    }
                }

                if (!empty($parameters)) {
                    $parsedComponents[] = [
                        "type" => "body",
                        "parameters" => $parameters
                    ];
                }
            }
        }

        return $parsedComponents;
    }

    /**
     * Save an outgoing campaign broadcast text as a message in the chat UI log.
     */
    private function logCampaignMessageAsChat(string $campaignId, $member, $crmRecord, string $templateName, string $metaMsgId): void
    {
        $phone = $member->get('phoneNumber');

        // Fetch or create conversation
        $conv = $this->entityManager->getRepository('WhatsAppConversation')
            ->where(['phoneNumber' => $phone])
            ->findOne();

        if (!$conv) {
            $conv = $this->entityManager->createEntity('WhatsAppConversation');
            $conv->set([
                'phoneNumber' => $phone,
                'customerName' => $crmRecord ? $crmRecord->get('name') : $phone,
                'mappedType' => $member->get('entityType'),
                'mappedId' => $member->get('entityId'),
                'status' => 'Open',
                'priority' => 'Medium',
                'createdAt' => date('Y-m-d H:i:s')
            ]);
            $this->entityManager->saveEntity($conv);
        }

        // Log message
        $msg = $this->entityManager->createEntity('WhatsAppMessage');
        $msg->set([
            'conversationId' => $conv->id,
            'metaMessageId' => $metaMsgId,
            'direction' => 'Outgoing',
            'type' => 'Template',
            'status' => 'Sent',
            'textContent' => "Sent Broadcast Template: *{$templateName}*",
            'templateName' => $templateName,
            'createdAt' => date('Y-m-d H:i:s')
        ]);
        $this->entityManager->saveEntity($msg);

        // Update conversation state
        $conv->set([
            'lastMessageTime' => date('Y-m-d H:i:s'),
            'lastMessageSnippet' => "Sent Template: {$templateName}"
        ]);
        $this->entityManager->saveEntity($conv);
    }
}
