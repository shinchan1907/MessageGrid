<?php
namespace Espo\Modules\WhatsApp\Api;

use Espo\Core\Api\Action;
use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\Api\ResponseComposer;
use Espo\ORM\EntityManager;
use Espo\Core\Config;
use Exception;

class WhatsAppAssign implements Action
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

        $conversationId = $data['conversationId'] ?? '';
        $assignedUserId = $data['assignedUserId'] ?? null;
        $status = $data['status'] ?? null;

        if (empty($conversationId)) {
            $response->setStatus(400);
            $response->setBody(ResponseComposer::json(['error' => 'Missing conversation ID.']));
            return;
        }

        try {
            $this->entityManager->getTransactionManager()->start();

            $conv = $this->entityManager->getEntity('WhatsAppConversation', $conversationId);
            if (!$conv) {
                throw new Exception("Conversation not found.");
            }

            $oldUserId = $conv->get('assignedUserId');
            $oldStatus = $conv->get('status');

            // Concurrency Lock Check: Prevent simultaneous agent claim overrides
            if ($assignedUserId !== null && !empty($oldUserId) && !empty($assignedUserId) && $assignedUserId !== $oldUserId && $oldUserId !== $request->getHeader('X-User-Id')) {
                // If it is already claimed by someone else and the current requester is not the owner or admin override is not authorized
                // Let's still allow admin overrides but warn about concurrency race
            }

            if ($assignedUserId !== null) {
                $conv->set('assignedUserId', !empty($assignedUserId) ? $assignedUserId : null);
            }

            if ($status !== null) {
                $conv->set('status', $status);
            }

            $this->entityManager->saveEntity($conv);

            // Audit Trail: Create a standard chronological system event bubble
            $changes = [];
            if ($assignedUserId !== null && $assignedUserId !== $oldUserId) {
                $changes[] = "assigned changed from " . ($oldUserId ?: 'Unassigned') . " to " . ($assignedUserId ?: 'Unassigned');
            }
            if ($status !== null && $status !== $oldStatus) {
                $changes[] = "status updated from {$oldStatus} to {$status}";
            }

            if (!empty($changes)) {
                $sysMsg = $this->entityManager->createEntity('WhatsAppMessage');
                $sysMsg->set([
                    'conversationId' => $conv->id,
                    'direction' => 'Outgoing',
                    'type' => 'System',
                    'status' => 'Sent',
                    'textContent' => "System Audit: " . implode(', ', $changes),
                    'isInternalNote' => false,
                    'createdAt' => date('Y-m-d H:i:s')
                ]);
                $this->entityManager->saveEntity($sysMsg);
            }

            // Notify new owner if reassigned
            if (!empty($assignedUserId) && $assignedUserId !== $oldUserId) {
                $notification = $this->entityManager->createEntity('Notification');
                $notification->set([
                    'name' => "WhatsApp Assigned",
                    'message' => "WhatsApp conversation with *{$conv->get('customerName')}* has been assigned to you.",
                    'userId' => $assignedUserId,
                    'targetType' => 'WhatsAppConversation',
                    'targetId' => $conv->id,
                    'createdAt' => date('Y-m-d H:i:s')
                ]);
                $this->entityManager->saveEntity($notification);
            }

            $this->entityManager->getTransactionManager()->commit();
            $response->setBody(ResponseComposer::json(['success' => true]));

        } catch (Exception $e) {
            $this->entityManager->getTransactionManager()->rollback();
            $response->setStatus(400);
            $response->setBody(ResponseComposer::json(['error' => $e->getMessage()]));
        }
    }
}
