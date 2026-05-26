<?php
namespace Espo\Modules\WhatsApp\Services;

use Espo\ORM\EntityManager;
use Espo\Core\Config;
use Espo\Core\User;
use Exception;
use DateTime;

class WhatsAppService
{
    public function __construct(
        private EntityManager $entityManager,
        private Config $config,
        private MetaApiService $metaApiService,
        private StorageService $storageService,
        private ?QueueService $queueService = null
    ) {
        if ($this->queueService === null) {
            $this->queueService = new QueueService($this->entityManager, $this->config, $this->metaApiService, $this->storageService);
        }
    }

    /**
     * Retrieve current settings with masked tokens.
     */
    public function getSettings(): array
    {
        $siteUrl = $this->config->get('siteUrl') ?: 'http://localhost';
        $webhookUrl = rtrim($siteUrl, '/') . '/?entryPoint=WhatsAppWebhook';
        
        $securityHelper = new \Espo\Modules\WhatsApp\Helpers\SecurityHelper($this->config);

        $tokenEncrypted = $this->config->get('whatsappMetaPermanentToken') ?: '';
        $token = $securityHelper->decrypt($tokenEncrypted);
        $maskedToken = !empty($token) ? substr($token, 0, 8) . '...' . substr($token, -8) : '';

        $secretEncrypted = $this->config->get('whatsappMetaAppSecret') ?: '';
        $secret = $securityHelper->decrypt($secretEncrypted);
        $maskedSecret = !empty($secret) ? substr($secret, 0, 4) . '...' . substr($secret, -4) : '';

        $s3AccessEncrypted = $this->config->get('whatsappS3AccessKey') ?: '';
        $s3AccessKey = $securityHelper->decrypt($s3AccessEncrypted);
        
        $s3SecretEncrypted = $this->config->get('whatsappS3SecretKey') ?: '';
        $s3SecretKey = $securityHelper->decrypt($s3SecretEncrypted);

        // Auto-generate verification token if empty
        $verifyToken = $this->config->get('whatsappMetaVerifyToken');
        if (empty($verifyToken)) {
            $verifyToken = bin2hex(random_bytes(16));
            $this->config->set('whatsappMetaVerifyToken', $verifyToken);
            $this->config->save();
        }

        return [
          'metaAppId' => $this->config->get('whatsappMetaAppId') ?: '',
          'metaAppSecret' => $secret,
          'metaAppSecretMasked' => $maskedSecret,
          'metaVerifyToken' => $verifyToken,
          'metaPermanentToken' => $token,
          'metaPermanentTokenMasked' => $maskedToken,
          'metaWabaId' => $this->config->get('whatsappMetaWabaId') ?: '',
          'metaPhoneNumberId' => $this->config->get('whatsappMetaPhoneNumberId') ?: '',
          'metaBusinessName' => $this->config->get('whatsappMetaBusinessName') ?: '',
          'webhookUrl' => $webhookUrl,
          'webhookToken' => $verifyToken,
          // Routing settings
          'autoCreateLead' => $this->config->get('whatsappAutoCreateLead') ?? false,
          'routingRule' => $this->config->get('whatsappRoutingRule') ?: 'StickyAgent', // StickyAgent, RoundRobin, Queue
          'blockOtherAgents' => $this->config->get('whatsappBlockOtherAgents') ?? false,
          'roundRobinIndex' => $this->config->get('whatsappRoundRobinIndex') ?: 0,
          // S3 Storage Settings
          's3Enabled' => $this->config->get('whatsappS3Enabled') ?? false,
          's3Bucket' => $this->config->get('whatsappS3Bucket') ?: '',
          's3Region' => $this->config->get('whatsappS3Region') ?: 'us-east-1',
          's3AccessKey' => $s3AccessKey,
          's3SecretKey' => $s3SecretKey
        ];
    }

    /**
     * Update settings inside Config.
     */
    public function saveSettings(array $settings): void
    {
        $securityHelper = new \Espo\Modules\WhatsApp\Helpers\SecurityHelper($this->config);

        $fields = [
            'metaAppId' => 'whatsappMetaAppId',
            'metaAppSecret' => 'whatsappMetaAppSecret',
            'metaVerifyToken' => 'whatsappMetaVerifyToken',
            'metaPermanentToken' => 'whatsappMetaPermanentToken',
            'metaWabaId' => 'whatsappMetaWabaId',
            'metaPhoneNumberId' => 'whatsappMetaPhoneNumberId',
            'metaBusinessName' => 'whatsappMetaBusinessName',
            'autoCreateLead' => 'whatsappAutoCreateLead',
            'routingRule' => 'whatsappRoutingRule',
            'blockOtherAgents' => 'whatsappBlockOtherAgents',
            's3Enabled' => 'whatsappS3Enabled',
            's3Bucket' => 'whatsappS3Bucket',
            's3Region' => 'whatsappS3Region',
            's3AccessKey' => 'whatsappS3AccessKey',
            's3SecretKey' => 'whatsappS3SecretKey',
        ];

        foreach ($fields as $key => $configKey) {
            if (isset($settings[$key])) {
                $value = $settings[$key];

                // Cryptographically encrypt sensitive keys before saving
                if (in_array($configKey, ['whatsappMetaPermanentToken', 'whatsappMetaAppSecret', 'whatsappS3AccessKey', 'whatsappS3SecretKey'])) {
                    if (strpos($value, '...') !== false) {
                        // Skip if the user submitted the masked value to preserve current key
                        continue;
                    }
                    $value = $securityHelper->encrypt($value);
                }

                $this->config->set($configKey, $value);
            }
        }

        $this->config->save();
    }

    /**
     * Map a phone number to a CRM Contact, Lead, or Account.
     */
    public function findMappedRecord(string $phoneNumber): ?array
    {
        // Strip out common number format characters for clean search
        $cleanPhone = preg_replace('/[^0-9]/', '', $phoneNumber);
        if (empty($cleanPhone)) {
            return null;
        }

        // 1. Search Contacts
        $contact = $this->entityManager->getRepository('Contact')
            ->where(['phoneNumber*=' => $cleanPhone]) // matches substring
            ->findOne();

        if ($contact) {
            return ['type' => 'Contact', 'id' => $contact->id, 'name' => $contact->get('name'), 'assignedUserId' => $contact->get('assignedUserId')];
        }

        // 2. Search Leads
        $lead = $this->entityManager->getRepository('Lead')
            ->where(['phoneNumber*=' => $cleanPhone])
            ->findOne();

        if ($lead) {
            return ['type' => 'Lead', 'id' => $lead->id, 'name' => $lead->get('name'), 'assignedUserId' => $lead->get('assignedUserId')];
        }

        // 3. Search Accounts
        $account = $this->entityManager->getRepository('Account')
            ->where(['phoneNumber*=' => $cleanPhone])
            ->findOne();

        if ($account) {
            return ['type' => 'Account', 'id' => $account->id, 'name' => $account->get('name'), 'assignedUserId' => $account->get('assignedUserId')];
        }

        return null;
    }

    /**
     * Dynamic routing logic for new incoming conversation.
     */
    private function routeConversation($conv, ?array $mapped): void
    {
        $rule = $this->config->get('whatsappRoutingRule') ?: 'StickyAgent';

        // Sticky Agent Rule
        if ($rule === 'StickyAgent' && $mapped && !empty($mapped['assignedUserId'])) {
            $conv->set('assignedUserId', $mapped['assignedUserId']);
            return;
        }

        // Round Robin Rule
        if ($rule === 'RoundRobin' || ($rule === 'StickyAgent' && (empty($mapped) || empty($mapped['assignedUserId'])))) {
            $agents = $this->entityManager->getRepository('User')
                ->where([
                    'isActive' => true,
                    'type' => 'regular'
                ])
                ->order('id', 'ASC')
                ->find();

            if (count($agents) > 0) {
                $currentIndex = (int)($this->config->get('whatsappRoundRobinIndex') ?? 0);
                if ($currentIndex >= count($agents)) {
                    $currentIndex = 0;
                }

                $assignedAgent = $agents[$currentIndex];
                $conv->set('assignedUserId', $assignedAgent->id);

                // Update Round Robin index
                $this->config->set('whatsappRoundRobinIndex', $currentIndex + 1);
                $this->config->save();
                return;
            }
        }

        // Fallback: Queue (Leave unassigned for queues)
        $conv->set('assignedUserId', null);
    }

    /**
     * Process Webhook Payloads safely.
     */
    public function processWebhookPayload(array $payload): void
    {
        $entries = $payload['entry'] ?? [];
        foreach ($entries as $entry) {
            $changes = $entry['changes'] ?? [];
            foreach ($changes as $change) {
                $value = $change['value'] ?? [];
                
                // 1. Process Message Delivery Statuses
                if (isset($value['statuses'])) {
                    foreach ($value['statuses'] as $statusUpdate) {
                        $metaMsgId = $statusUpdate['id'] ?? null;
                        $status = $statusUpdate['status'] ?? null;
                        
                        if ($metaMsgId && $status) {
                            $this->updateMessageStatus($metaMsgId, $status, $statusUpdate['errors'] ?? null);
                        }
                    }
                }

                // 2. Process Incoming Messages
                if (isset($value['messages'])) {
                    $metadata = $value['metadata'] ?? [];
                    $contacts = $value['contacts'] ?? [];
                    $profileName = $contacts[0]['profile']['name'] ?? 'WhatsApp Contact';

                    foreach ($value['messages'] as $messageData) {
                        $this->handleIncomingMessage($messageData, $profileName);
                    }
                }
            }
        }
    }

    /**
     * Handle single incoming WhatsApp message.
     */
    private function handleIncomingMessage(array $data, string $profileName): void
    {
        $phone = $data['from'] ?? null;
        $metaMsgId = $data['id'] ?? null;
        $type = $data['type'] ?? 'text';

        if (!$phone || !$metaMsgId) {
            return;
        }

        // Avoid duplicate logging
        $existing = $this->entityManager->getRepository('WhatsAppMessage')
            ->where(['metaMessageId' => $metaMsgId])
            ->findOne();
        if ($existing) {
            return;
        }

        // Map CRM Contact / Lead
        $mapped = $this->findMappedRecord($phone);
        
        // Auto create lead if checked
        if (!$mapped && $this->config->get('whatsappAutoCreateLead')) {
            $lead = $this->entityManager->createEntity('Lead');
            $lead->set([
                'lastName' => $profileName,
                'phoneNumber' => $phone,
                'leadSource' => 'WhatsApp',
                'description' => 'Auto-created from incoming WhatsApp chat.',
                'createdAt' => date('Y-m-d H:i:s')
            ]);
            $this->entityManager->saveEntity($lead);
            $mapped = [
                'type' => 'Lead',
                'id' => $lead->id,
                'name' => $lead->get('name'),
                'assignedUserId' => null
            ];
        }

        // Find or create Conversation
        $conv = $this->entityManager->getRepository('WhatsAppConversation')
            ->where(['phoneNumber' => $phone])
            ->findOne();

        if (!$conv) {
            $conv = $this->entityManager->createEntity('WhatsAppConversation');
            $conv->set([
                'phoneNumber' => $phone,
                'customerName' => $mapped ? $mapped['name'] : $profileName,
                'mappedType' => $mapped ? $mapped['type'] : 'None',
                'mappedId' => $mapped ? $mapped['id'] : '',
                'status' => 'Pending',
                'priority' => 'Medium',
                'unreadCount' => 0,
                'createdAt' => date('Y-m-d H:i:s')
            ]);
            // Run Sticky ownership routing
            $this->routeConversation($conv, $mapped);
            $this->entityManager->saveEntity($conv);
        } else {
            // Mapped state sync
            if ($mapped && $conv->get('mappedType') === 'None') {
                $conv->set([
                    'mappedType' => $mapped['type'],
                    'mappedId' => $mapped['id'],
                    'customerName' => $mapped['name']
                ]);
            }
            $conv->set('status', 'Open'); // Reopen chats on new message
        }

        // Log message
        $msg = $this->entityManager->createEntity('WhatsAppMessage');
        $msg->set([
            'conversationId' => $conv->id,
            'metaMessageId' => $metaMsgId,
            'direction' => 'Incoming',
            'type' => ucfirst($type),
            'status' => 'Read', // Meta treats incoming messages as read instantly by customer
            'createdAt' => date('Y-m-d H:i:s')
        ]);

        $snippet = "Incoming media file";

        switch ($type) {
            case 'text':
                $body = $data['text']['body'] ?? '';
                $msg->set('textContent', $body);
                $snippet = strlen($body) > 40 ? substr($body, 0, 40) . '...' : $body;
                break;
            case 'image':
                $imgId = $data['image']['id'] ?? '';
                $mime = $data['image']['mime_type'] ?? 'image/jpeg';
                $caption = $data['image']['caption'] ?? '';
                $msg->set('textContent', $caption ?: 'Received Image');
                // Enqueue background downloader
                $this->queueService->push('MediaDownloader', [
                    'messageId' => $msg->id,
                    'mediaId' => $imgId,
                    'mimeType' => $mime,
                    'filename' => 'image'
                ]);
                $snippet = "🖼️ Photo attachment";
                break;
            case 'document':
                $docId = $data['document']['id'] ?? '';
                $mime = $data['document']['mime_type'] ?? 'application/pdf';
                $filename = $data['document']['filename'] ?? 'document';
                $caption = $data['document']['caption'] ?? '';
                $msg->set('textContent', ($caption ?: $filename) ?: 'Received Document');
                $this->queueService->push('MediaDownloader', [
                    'messageId' => $msg->id,
                    'mediaId' => $docId,
                    'mimeType' => $mime,
                    'filename' => $filename
                ]);
                $snippet = "📄 Document: " . $filename;
                break;
            case 'audio':
                $audioId = $data['audio']['id'] ?? '';
                $mime = $data['audio']['mime_type'] ?? 'audio/ogg';
                $msg->set('textContent', 'Voice Message');
                $this->queueService->push('MediaDownloader', [
                    'messageId' => $msg->id,
                    'mediaId' => $audioId,
                    'mimeType' => $mime,
                    'filename' => 'voice'
                ]);
                $snippet = "🎵 Voice Note";
                break;
            case 'video':
                $vidId = $data['video']['id'] ?? '';
                $mime = $data['video']['mime_type'] ?? 'video/mp4';
                $caption = $data['video']['caption'] ?? '';
                $msg->set('textContent', $caption ?: 'Received Video');
                $this->queueService->push('MediaDownloader', [
                    'messageId' => $msg->id,
                    'mediaId' => $vidId,
                    'mimeType' => $mime,
                    'filename' => 'video'
                ]);
                $snippet = "📹 Video attachment";
                break;
            case 'interactive':
                $interactiveType = $data['interactive']['type'] ?? '';
                $buttonText = '';
                if ($interactiveType === 'button_reply') {
                    $buttonText = $data['interactive']['button_reply']['title'] ?? '';
                } elseif ($interactiveType === 'list_reply') {
                    $buttonText = $data['interactive']['list_reply']['title'] ?? '';
                }
                $msg->set('textContent', $buttonText ?: 'Replied to interactive message');
                $snippet = "🔘 Button reply: " . $buttonText;
                break;
            case 'button':
                $buttonText = $data['button']['text'] ?? '';
                $msg->set('textContent', $buttonText);
                $snippet = "🔘 Clicked Button: " . $buttonText;
                break;
            default:
                $msg->set('textContent', "[Unsupported message type: {$type}]");
                $snippet = "Message received";
        }

        $this->entityManager->saveEntity($msg);

        // Update Conversation Time & Badges
        $conv->set([
            'unreadCount' => $conv->get('unreadCount') + 1,
            'lastMessageTime' => date('Y-m-d H:i:s'),
            'lastMessageSnippet' => $snippet,
            'slaExpiresAt' => date('Y-m-d H:i:s', strtotime('+4 hours')) // SLA default warning in 4 hours
        ]);
        $this->entityManager->saveEntity($conv);

        // Trigger In-App Notification
        $ownerId = $conv->get('assignedUserId');
        if (!empty($ownerId)) {
            $notification = $this->entityManager->createEntity('Notification');
            $notification->set([
                'name' => "New WhatsApp Chat",
                'message' => "Received new WhatsApp chat from *{$conv->get('customerName')}*: {$snippet}",
                'userId' => $ownerId,
                'targetType' => 'WhatsAppConversation',
                'targetId' => $conv->id,
                'createdAt' => date('Y-m-d H:i:s')
            ]);
            $this->entityManager->saveEntity($notification);
        }
    }

    /**
     * Update outgoing status ticks.
     */
    private function updateMessageStatus(string $metaMsgId, string $status, ?array $errors): void
    {
        $msg = $this->entityManager->getRepository('WhatsAppMessage')
            ->where(['metaMessageId' => $metaMsgId])
            ->findOne();

        if ($msg) {
            $msg->set('status', ucfirst($status));
            if ($status === 'failed' && !empty($errors)) {
                $err = $errors[0]['message'] ?? 'Meta delivery error';
                $msg->set('errorMessage', $err);
            }
            $this->entityManager->saveEntity($msg);
        }

        // Update Campaign Member status too
        $member = $this->entityManager->getRepository('WhatsAppCampaignMember')
            ->where(['metaMessageId' => $metaMsgId])
            ->findOne();

        if ($member) {
            $oldStatus = $member->get('status');
            $member->set('status', ucfirst($status));
            if ($status === 'failed' && !empty($errors)) {
                $err = $errors[0]['message'] ?? 'Meta delivery error';
                $member->set('errorMessage', $err);
            }
            $this->entityManager->saveEntity($member);

            // Dynamically increment analytics fields on Campaign
            $campaign = $this->entityManager->getEntity('WhatsAppCampaign', $member->get('campaignId'));
            if ($campaign) {
                if ($status === 'delivered' && $oldStatus !== 'Delivered') {
                    $campaign->set('deliveredCount', $campaign->get('deliveredCount') + 1);
                } elseif ($status === 'read' && $oldStatus !== 'Read') {
                    // Count both read and delivered
                    $campaign->set('readCount', $campaign->get('readCount') + 1);
                    if ($oldStatus !== 'Delivered') {
                        $campaign->set('deliveredCount', $campaign->get('deliveredCount') + 1);
                    }
                } elseif ($status === 'failed' && $oldStatus !== 'Failed') {
                    $campaign->set('failedCount', $campaign->get('failedCount') + 1);
                }
                $this->entityManager->saveEntity($campaign);
            }
        }
    }

    /**
     * Send Outgoing Message from Agent.
     */
    public function sendChatMessage(string $convId, string $agentId, string $type, array $content): array
    {
        $conv = $this->entityManager->getEntity('WhatsAppConversation', $convId);
        if (!$conv) {
            throw new Exception("Conversation not found.");
        }

        // Agent Ownership Rule Enforcement
        $block = $this->config->get('whatsappBlockOtherAgents') ?? false;
        $owner = $conv->get('assignedUserId');
        if ($block && !empty($owner) && $owner !== $agentId) {
            $currentUser = $this->entityManager->getEntity('User', $agentId);
            if ($currentUser && !$currentUser->get('isAdmin')) {
                throw new Exception("Sticky Agent routing is active. Only the assigned agent can reply to this chat.");
            }
        }

        // Call API
        $res = $this->metaApiService->sendMessage(
            $conv->get('phoneNumber'),
            $type,
            $content
        );

        $metaMsgId = $res['messages'][0]['id'] ?? 'msg_' . uniqid();

        // Log local WhatsAppMessage
        $msg = $this->entityManager->createEntity('WhatsAppMessage');
        $msg->set([
            'conversationId' => $conv->id,
            'metaMessageId' => $metaMsgId,
            'direction' => 'Outgoing',
            'type' => ucfirst($type),
            'status' => 'Sent',
            'senderId' => $agentId,
            'textContent' => $content['body'] ?? ($content['caption'] ?? 'Outgoing media file'),
            'createdAt' => date('Y-m-d H:i:s')
        ]);

        if (isset($content['link'])) {
            $msg->set('mediaUrl', $content['link']);
        }

        $this->entityManager->saveEntity($msg);

        // Reset unread count and update snippet
        $conv->set([
            'unreadCount' => 0,
            'lastMessageTime' => date('Y-m-d H:i:s'),
            'lastMessageSnippet' => $content['body'] ?? "Sent attachment"
        ]);
        $this->entityManager->saveEntity($conv);

        return [
            'success' => true,
            'message' => $msg->toArray()
        ];
    }

    /**
     * Create an internal private agent note.
     */
    public function createInternalNote(string $convId, string $agentId, string $noteContent): array
    {
        $conv = $this->entityManager->getEntity('WhatsAppConversation', $convId);
        if (!$conv) {
            throw new Exception("Conversation not found.");
        }

        $msg = $this->entityManager->createEntity('WhatsAppMessage');
        $msg->set([
            'conversationId' => $conv->id,
            'direction' => 'Outgoing',
            'type' => 'System',
            'status' => 'Sent',
            'senderId' => $agentId,
            'textContent' => $noteContent,
            'isInternalNote' => true,
            'createdAt' => date('Y-m-d H:i:s')
        ]);
        $this->entityManager->saveEntity($msg);

        return [
            'success' => true,
            'message' => $msg->toArray()
        ];
    }

    /**
     * Retrieve analytics aggregated dashboard.
     */
    public function getAnalyticsDashboard(): array
    {
        $today = date('Y-m-d 00:00:00');

        $totalToday = $this->entityManager->getRepository('WhatsAppMessage')
            ->where([
                'createdAt>=' => $today,
                'isInternalNote' => false
            ])
            ->count();

        $activeAgents = $this->entityManager->getRepository('WhatsAppConversation')
            ->where([
                'assignedUserId!=' => null,
                'status' => 'Open'
            ])
            ->count(); // basic approximation based on open chats

        $openChats = $this->entityManager->getRepository('WhatsAppConversation')
            ->where(['status' => 'Open'])
            ->count();

        $pendingChats = $this->entityManager->getRepository('WhatsAppConversation')
            ->where(['status' => 'Pending'])
            ->count();

        // Calculate delivery rate percentages
        $sentCount = $this->entityManager->getRepository('WhatsAppMessage')
            ->where(['direction' => 'Outgoing'])
            ->count() ?: 1;

        $deliveredCount = $this->entityManager->getRepository('WhatsAppMessage')
            ->where([
                'direction' => 'Outgoing',
                'status' => ['Delivered', 'Read']
            ])
            ->count();

        $readCount = $this->entityManager->getRepository('WhatsAppMessage')
            ->where([
                'direction' => 'Outgoing',
                'status' => 'Read'
            ])
            ->count();

        $failedCount = $this->entityManager->getRepository('WhatsAppMessage')
            ->where([
                'direction' => 'Outgoing',
                'status' => 'Failed'
            ])
            ->count();

        $deliveryRate = round(($deliveredCount / $sentCount) * 100, 1);
        $readRate = round(($readCount / $sentCount) * 100, 1);

        return [
            'totalMessagesToday' => $totalToday,
            'activeAgents' => $activeAgents ?: 1,
            'deliveryRate' => $deliveryRate,
            'readRate' => $readRate,
            'openConversations' => $openChats,
            'pendingConversations' => $pendingChats,
            'failedMessages' => $failedCount
        ];
    }

    /**
     * Purge historical messages and their corresponding file attachments
     * based on configurable GDPR data retention limits.
     */
    public function cleanupRetentionData(): array
    {
        $retentionDays = intval($this->config->get('whatsappRetentionDays') ?? 180);
        if ($retentionDays <= 0) {
            return ['status' => 'disabled', 'purged' => 0];
        }

        $cutoffDate = date('Y-m-d H:i:s', strtotime("-{$retentionDays} days"));
        $messages = $this->entityManager->getRepository('WhatsAppMessage')
            ->where([
                'createdAt<' => $cutoffDate
            ])
            ->limit(500) // Chunk size to prevent server memory crashes
            ->find();

        $purgedCount = 0;
        foreach ($messages as $msg) {
            $mediaPath = $msg->get('mediaPath');
            if (!empty($mediaPath)) {
                try {
                    $this->storageService->deleteFile($mediaPath);
                } catch (Exception) {
                    // Fail silently to ensure message record is still cleaned up
                }
            }
            $this->entityManager->removeEntity($msg);
            $purgedCount++;
        }

        // Clean up webhook logs older than 30 days
        try {
            $logCutoff = date('Y-m-d H:i:s', strtotime('-30 days'));
            $oldLogs = $this->entityManager->getRepository('WhatsAppWebhookLog')
                ->where(['createdAt<' => $logCutoff])
                ->limit(500)
                ->find();
            foreach ($oldLogs as $log) {
                $this->entityManager->removeEntity($log);
            }
        } catch (Exception) {
            // Suppress secondary failures
        }

        return ['status' => 'success', 'purged' => $purgedCount];
    }
}

