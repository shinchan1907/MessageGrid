<?php
class BeforeInstall
{
    public function run($container): void
    {
        // 1. Check PHP version
        if (version_compare(PHP_VERSION, '7.4.0', '<')) {
            throw new Exception("This WhatsApp extension requires PHP version 7.4.0 or greater. Your current version is " . PHP_VERSION);
        }

        // 2. Check curl extension
        if (!extension_loaded('curl')) {
            throw new Exception("The 'curl' PHP extension is required to connect to the Meta Cloud API. Please enable it in php.ini.");
        }

        // 3. Check JSON support
        if (!extension_loaded('json')) {
            throw new Exception("The 'json' PHP extension is required to parse webhook payloads.");
        }
    }
}
