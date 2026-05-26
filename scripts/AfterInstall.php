<?php
class AfterInstall
{
    public function run($container): void
    {
        // 1. Prepare local upload folder for WhatsApp attachments
        $baseDir = defined('ROOT_PATH') ? ROOT_PATH : dirname(__DIR__);
        $uploadDir = $baseDir . '/data/upload/whatsapp_media/';
        if (!is_dir($uploadDir)) {
            @mkdir($uploadDir, 0775, true);
        }

        // 2. Perform EspoCRM Rebuild/Clear Cache to register new entities, scopes, and routes
        try {
            $rebuilt = false;

            // Try using DataManager if available (modern EspoCRM standard)
            if (is_object($container) && method_exists($container, 'getByClass')) {
                try {
                    $dataManager = $container->getByClass('Espo\Core\DataManager');
                    if (is_object($dataManager)) {
                        if (method_exists($dataManager, 'rebuild')) {
                            $dataManager->rebuild();
                            $rebuilt = true;
                        }
                        if (method_exists($dataManager, 'clearCache')) {
                            $dataManager->clearCache();
                        }
                    }
                } catch (\Throwable $t) {
                    // Ignore and try alternative methods
                }
            }

            // Fallback 1: Try 'rebuild' service
            if (!$rebuilt && is_object($container) && method_exists($container, 'has') && $container->has('rebuild')) {
                $rebuildService = $container->get('rebuild');
                if (is_object($rebuildService) && method_exists($rebuildService, 'rebuild')) {
                    $rebuildService->rebuild();
                    $rebuilt = true;
                }
            }

            // Fallback 2: Check entityManager just in case it has clearCache (safeguarded via method_exists)
            if (!$rebuilt && is_object($container) && method_exists($container, 'has') && $container->has('entityManager')) {
                $entityManager = $container->get('entityManager');
                if (is_object($entityManager) && method_exists($entityManager, 'clearCache')) {
                    $entityManager->clearCache();
                }
            }
        } catch (\Throwable $e) {
            // Log error silently, let user rebuild manually via UI if needed
            error_log("WhatsApp Post-Install Rebuild Warning: " . $e->getMessage());
        }
    }
}
