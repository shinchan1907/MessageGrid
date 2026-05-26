<?php
class AfterInstall
{
    public function run($container): void
    {
        // 1. Prepare local upload folder for WhatsApp attachments
        $uploadDir = dirname(__DIR__) . '/data/upload/whatsapp_media/';
        if (!is_dir($uploadDir)) {
            @mkdir($uploadDir, 0775, true);
        }

        // 2. Perform EspoCRM Rebuild to register the new entities, scopes, and routes
        try {
            if ($container->has('rebuild')) {
                $rebuildService = $container->get('rebuild');
                if (method_exists($rebuildService, 'rebuild')) {
                    $rebuildService->rebuild();
                }
            } elseif ($container->has('entityManager')) {
                // Alternative: Clear cache directly if rebuild service name differs
                $entityManager = $container->get('entityManager');
                $entityManager->clearCache();
            }
        } catch (Exception $e) {
            // Log error silently, let user rebuild manually via UI
            error_log("WhatsApp Post-Install Rebuild Warning: " . $e->getMessage());
        }
    }
}
