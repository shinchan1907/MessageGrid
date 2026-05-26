<?php
namespace Espo\Modules\WhatsApp\Helpers;

use Espo\Core\Config;

class SaaSManager
{
    private string $currentTenantId = 'default';

    public function __construct(private Config $config)
    {
        // Dynamically deduce current tenant context (e.g. from hostname or multi-tenant headers)
        $this->currentTenantId = $this->resolveTenantContext();
    }

    /**
     * Deduce tenant identity using environment variables or tenant-switch HTTP headers.
     */
    private function resolveTenantContext(): string
    {
        if (isset($_SERVER['HTTP_X_TENANT_ID'])) {
            return preg_replace('/[^a-zA-Z0-9_-]/', '', $_SERVER['HTTP_X_TENANT_ID']);
        }
        if (isset($_ENV['ESPO_TENANT_ID'])) {
            return $_ENV['ESPO_TENANT_ID'];
        }
        return 'default';
    }

    public function getTenantId(): string
    {
        return $this->currentTenantId;
    }

    /**
     * Resolves isolated configuration options tailored per tenant.
     */
    public function getTenantConfig(string $key, mixed $default = null): mixed
    {
        // In full SaaS mode, this queries a tenant-specific table.
        // Currently, it acts as a reliable abstraction wrapping Espo's Config API.
        $tenantKey = "whatsapp_" . $this->currentTenantId . "_" . $key;
        return $this->config->get($tenantKey) ?: $this->config->get("whatsapp" . ucfirst($key)) ?: $default;
    }

    /**
     * Resolves isolated, per-tenant S3 upload folders and bucket definitions.
     */
    public function getTenantStoragePrefix(): string
    {
        return "tenants/" . $this->currentTenantId . "/media/";
    }

    /**
     * Resolves isolated queue routing keys to support dedicated worker pools.
     */
    public function getTenantQueueRoutingKey(): string
    {
        return "queue_tenant_" . $this->currentTenantId;
    }

    /**
     * central feature flag controller to toggle advanced system features.
     */
    public function isFeatureEnabled(string $featureName): bool
    {
        // Fetch toggles from tenant configurations, falling back to secure defaults
        switch ($featureName) {
            case 'WebSockets':
                return (bool) $this->getTenantConfig('enableWebSockets', true);
            case 'Broadcasts':
                return (bool) $this->getTenantConfig('enableBroadcasts', true);
            case 'AIHooks':
                return (bool) $this->getTenantConfig('enableAIHooks', false);
            case 'ChatbotSupport':
                return (bool) $this->getTenantConfig('enableChatbot', false);
            case 'MediaAutoDownload':
                return (bool) $this->getTenantConfig('enableMediaAutoDownload', true);
            case 'ReadReceipts':
                return (bool) $this->getTenantConfig('enableReadReceipts', true);
            case 'AdvancedAnalytics':
                return (bool) $this->getTenantConfig('enableAdvancedAnalytics', true);
            default:
                return false;
        }
    }
}
