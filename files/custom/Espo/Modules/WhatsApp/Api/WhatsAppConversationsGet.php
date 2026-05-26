<?php
namespace Espo\Modules\WhatsApp\Api;

use Espo\Core\Api\Action;
use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\Api\ResponseComposer;
use Espo\ORM\EntityManager;
use Espo\Core\Config;

class WhatsAppConversationsGet implements Action
{
    public function __construct(
        private EntityManager $entityManager,
        private Config $config
    ) {}

    public function process(Request $request, Response $response): void
    {
        $params = $request->getQueryParams();
        $filter = $params['filter'] ?? 'all';
        $search = $params['search'] ?? '';
        $currentUserId = $params['currentUserId'] ?? '';

        $query = $this->entityManager->getRepository('WhatsAppConversation');
        $where = [];

        // Apply Filters
        switch ($filter) {
            case 'unread':
                $where['unreadCount>'] = 0;
                $where['status!='] = 'Resolved';
                break;
            case 'assigned':
                if (!empty($currentUserId)) {
                    $where['assignedUserId'] = $currentUserId;
                }
                $where['status!='] = 'Resolved';
                break;
            case 'pending':
                $where['status'] = 'Pending';
                break;
            case 'resolved':
                $where['status'] = 'Resolved';
                break;
            default:
                // Show non-resolved by default
                $where['status!='] = 'Resolved';
                break;
        }

        // Apply search keyword
        if (!empty($search)) {
            $where['OR'] = [
                'phoneNumber*=' => $search,
                'customerName*=' => $search,
                'lastMessageSnippet*=' => $search,
                'tags*=' => $search
            ];
        }

        $conversations = $query->where($where)
            ->order('lastMessageTime', 'DESC')
            ->find();

        $output = [];
        foreach ($conversations as $conv) {
            $data = $conv->toArray();
            
            // Mapped CRM entity details
            if ($conv->get('mappedType') !== 'None' && !empty($conv->get('mappedId'))) {
                $crmRecord = $this->entityManager->getEntity($conv->get('mappedType'), $conv->get('mappedId'));
                if ($crmRecord) {
                    $data['crmName'] = $crmRecord->get('name') ?: $crmRecord->get('lastName');
                    $data['crmOwner'] = $crmRecord->get('assignedUserId');
                }
            }

            // Mapped Assigned User Details
            if (!empty($conv->get('assignedUserId'))) {
                $user = $this->entityManager->getEntity('User', $conv->get('assignedUserId'));
                if ($user) {
                    $data['assignedUserName'] = $user->get('name');
                }
            }

            $output[] = $data;
        }

        $response->setBody(ResponseComposer::json($output));
    }
}
