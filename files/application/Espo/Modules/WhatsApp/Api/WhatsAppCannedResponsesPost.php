<?php
namespace Espo\Modules\WhatsApp\Api;

use Espo\Core\Api\Action;
use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\Api\ResponseComposer;
use Espo\ORM\EntityManager;
use Espo\Core\Config;
use Exception;

class WhatsAppCannedResponsesPost implements Action
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

        $shortcut = trim($data['shortcut'] ?? '');
        $content = trim($data['content'] ?? '');

        if (empty($shortcut) || empty($content)) {
            $response->setStatus(400);
            $response->setBody(ResponseComposer::json(['error' => 'Missing shortcut or content fields.']));
            return;
        }

        // Auto prefix with slash if missing
        if ($shortcut[0] !== '/') {
            $shortcut = '/' . $shortcut;
        }

        try {
            $existing = $this->entityManager->getRepository('WhatsAppCannedResponse')
                ->where(['shortcut' => $shortcut])
                ->findOne();

            if (!$existing) {
                $existing = $this->entityManager->createEntity('WhatsAppCannedResponse');
                $existing->set('createdAt', date('Y-m-d H:i:s'));
            }

            $existing->set([
                'shortcut' => $shortcut,
                'content' => $content
            ]);

            $this->entityManager->saveEntity($existing);

            $response->setBody(ResponseComposer::json(['success' => true, 'response' => $existing->toArray()]));

        } catch (Exception $e) {
            $response->setStatus(400);
            $response->setBody(ResponseComposer::json(['error' => $e->getMessage()]));
        }
    }
}
