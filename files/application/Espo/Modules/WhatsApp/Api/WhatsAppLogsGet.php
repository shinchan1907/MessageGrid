<?php
namespace Espo\Modules\WhatsApp\Api;

use Espo\Core\Api\Action;
use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\Api\ResponseComposer;
use Espo\ORM\EntityManager;
use Espo\Core\Config;

class WhatsAppLogsGet implements Action
{
    public function __construct(
        private EntityManager $entityManager,
        private Config $config
    ) {}

    public function process(Request $request, Response $response): void
    {
        $params = $request->getQueryParams();
        $status = $params['status'] ?? 'all';
        $search = $params['search'] ?? '';

        $query = $this->entityManager->getRepository('WhatsAppWebhookLog');
        $where = [];

        // Apply filters
        if ($status === 'success') {
            $where['status'] = 'Success';
        } elseif ($status === 'error') {
            $where['status'] = 'Error';
        }

        // Apply payload keyword search
        if (!empty($search)) {
            $where['OR'] = [
                'payload*=' => $search,
                'errorMessage*=' => $search
            ];
        }

        // Retrieve most recent 100 entries to prevent memory exhaustion
        $logs = $query->where($where)
            ->order('createdAt', 'DESC')
            ->limit(100)
            ->find();

        $output = [];
        foreach ($logs as $log) {
            $data = $log->toArray();
            
            // Limit payload rendering size in lists for speed
            if (strlen($data['payload']) > 300) {
                $data['payloadSnippet'] = substr($data['payload'], 0, 300) . '...';
            } else {
                $data['payloadSnippet'] = $data['payload'];
            }

            $output[] = $data;
        }

        $response->setBody(ResponseComposer::json($output));
    }
}
