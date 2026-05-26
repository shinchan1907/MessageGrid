<?php
/**
 * WhatsApp Extension Background Queue Worker Runner
 */

// Step 1: Locate and load EspoCRM bootstrap
$espoRoot = dirname(dirname(dirname(dirname(dirname(__DIR__)))));
if (!file_exists($espoRoot . '/bootstrap.php')) {
    die("Error: EspoCRM bootstrap.php not found at: {$espoRoot}\n");
}

require_once $espoRoot . '/bootstrap.php';

try {
    // Step 2: Initialize Espo application container
    $app = new \Espo\Core\Application();
    $container = $app->getContainer();

    $entityManager = $container->get('entityManager');
    $config = $container->get('config');

    // Step 3: Instantiate core services
    $metaApiService = new \Espo\Modules\WhatsApp\Services\MetaApiService($config);
    $storageService = new \Espo\Modules\WhatsApp\Services\StorageService($config);
    $queueService = new \Espo\Modules\WhatsApp\Services\QueueService(
        $entityManager,
        $config,
        $metaApiService,
        $storageService
    );

    // Step 4: Run queue processor
    echo "[" . date('Y-m-d H:i:s') . "] Starting WhatsApp Queue Worker...\n";
    $count = $queueService->runQueue(30); // process up to 30 pending tasks per execution
    echo "[" . date('Y-m-d H:i:s') . "] Finished. Processed {$count} queue jobs.\n";

} catch (Exception $e) {
    echo "[" . date('Y-m-d H:i:s') . "] Critical Queue Worker Error: " . $e->getMessage() . "\n";
    exit(1);
}
