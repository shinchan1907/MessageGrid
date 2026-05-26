<?php
/**
 * WhatsApp Extension Installable ZIP Compiler Build Script
 */

$packageName = 'whatsapp-integration-1.0.7.zip';

if (file_exists($packageName)) {
    @unlink($packageName);
}

if (!class_exists('ZipArchive')) {
    die("Error: PHP ZipArchive class is not enabled. Please install php-zip extension.\n");
}

$zip = new ZipArchive();
if ($zip->open($packageName, ZipArchive::CREATE) !== true) {
    die("Error: Could not create zip archive: {$packageName}\n");
}

// Helper to recursively add folders
function addFolderToZip($dir, $zip, $localPathPrefix = '') {
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir),
        RecursiveIteratorIterator::LEAVES_ONLY
    );

    foreach ($files as $name => $file) {
        if (!$file->isDir()) {
            $filePath = $file->getRealPath();
            
            // Calculate ZIP relative path
            $relativePath = $localPathPrefix . substr($filePath, strlen($dir));
            $relativePath = str_replace('\\', '/', $relativePath); // normalize separators

            $zip->addFile($filePath, $relativePath);
        }
    }
}

echo "Starting compilation...\n";

// Add Manifest, License, and Readme
$zip->addFile('manifest.json', 'manifest.json');
$zip->addFile('LICENSE', 'LICENSE');
$zip->addFile('README.md', 'README.md');

// Add Scripts and Files folders
addFolderToZip(__DIR__ . '/scripts', $zip, 'scripts/');
addFolderToZip(__DIR__ . '/files', $zip, 'files/');

$zip->close();

if (file_exists($packageName)) {
    echo "========================================================\n";
    echo "SUCCESS: Installable EspoCRM Extension Compiled!\n";
    echo "Archive Generated: " . realpath($packageName) . "\n";
    echo "Size: " . round(filesize($packageName) / 1024, 2) . " KB\n";
    echo "========================================================\n";
    echo "Upload this ZIP directly via Admin -> Extensions in EspoCRM.\n";
} else {
    echo "Error compiling archive.\n";
}
