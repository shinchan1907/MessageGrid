<?php
namespace Espo\Modules\WhatsApp\Api;

use Espo\Core\Api\Action;
use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\Api\ResponseComposer;
use Espo\ORM\EntityManager;
use Espo\Core\Config;

class WhatsAppMessagesGet implements Action
{
    public function __construct(
        private EntityManager $entityManager,
        private Config $config
    ) {}

    public function process(Request $request, Response $response): void
    {
        $params = $request->getQueryParams();
        $conversationId = $params['conversationId'] ?? '';

        if (empty($conversationId)) {
            $response->setStatus(400);
            $response->setBody(ResponseComposer::json(['error' => 'Missing conversationId parameter.']));
            return;
        }

        $conv = $this->entityManager->getEntity('WhatsAppConversation', $conversationId);
        if (!$conv) {
            $response->setStatus(404);
            $response->setBody(ResponseComposer::json(['error' => 'Conversation not found.']));
            return;
        }

        // Zero out unread count upon agent opening the chat stream
        if ($conv->get('unreadCount') > 0) {
            $conv->set('unreadCount', 0);
            $this->entityManager->saveEntity($conv);
        }

        // Fetch messages
        $messages = $this->entityManager->getRepository('WhatsAppMessage')
            ->where(['conversationId' => $conversationId])
            ->order('createdAt', 'ASC')
            ->find();

        $output = [];
        $siteUrl = rtrim($this->config->get('siteUrl') ?: 'http://localhost', '/');
        
        foreach ($messages as $msg) {
            $data = $msg->toArray();

            // Transform internal media paths into our secure local media entrypoint URL
            if (!empty($data['mediaUrl']) && strpos($data['mediaUrl'], 'local://') === 0) {
                $data['mediaServeUrl'] = "{$siteUrl}/?entryPoint=WhatsAppMedia&id={$msg->id}";
            } elseif (!empty($data['mediaUrl']) && strpos($data['mediaUrl'], 's3://') === 0) {
                // If S3, serve also through our secure entry point so agent remains authorized
                $data['mediaServeUrl'] = "{$siteUrl}/?entryPoint=WhatsAppMedia&id={$msg->id}";
            }

            // Mapped Agent name details
            if (!empty($data['senderId'])) {
                $agent = $this->entityManager->getEntity('User', $data['senderId']);
                if ($agent) {
                    $data['senderName'] = $agent->get('name');
                }
            }

            $output[] = $data;
        }

        $response->setBody(ResponseComposer::json($output));
    }
}
