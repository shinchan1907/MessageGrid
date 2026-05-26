<?php
namespace Espo\Modules\WhatsApp\EntryPoints;

use Espo\Core\EntryPoint\EntryPoint;
use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\ORM\EntityManager;
use Espo\Core\Config;
use Espo\Modules\WhatsApp\Services\StorageService;

class WhatsAppMedia implements EntryPoint
{
    // NO 'use NoAuth;' trait is added. This automatically restricts access to authenticated users only.

    public function __construct(
        private EntityManager $entityManager,
        private Config $config
    ) {}

    public function run(Request $request, Response $response): void
    {
        $params = $request->getQueryParams();
        $messageId = $params['id'] ?? '';

        if (empty($messageId)) {
            $response->setStatus(400);
            $response->write("Missing message ID parameter.");
            return;
        }

        $message = $this->entityManager->getEntity('WhatsAppMessage', $messageId);
        if (!$message) {
            $response->setStatus(404);
            $response->write("Attachment not found.");
            return;
        }

        $mediaUrl = $message->get('mediaUrl');
        if (empty($mediaUrl)) {
            $response->setStatus(404);
            $response->write("No media file associated with this message.");
            return;
        }

        $storageService = new StorageService($this->config);
        $binary = $storageService->getMediaBinary($mediaUrl);

        if (!$binary) {
            $response->setStatus(404);
            $response->write("Media file could not be retrieved from storage.");
            return;
        }

        $mimeType = $message->get('mediaMimeType') ?: 'application/octet-stream';

        // Output file stream with appropriate headers
        $response->setHeader('Content-Type', $mimeType);
        $response->setHeader('Content-Length', (string)strlen($binary));
        $response->setHeader('Cache-Control', 'max-age=86400, public');
        
        $response->write($binary);
    }
}
