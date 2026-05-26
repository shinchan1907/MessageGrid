<?php
namespace Espo\Modules\WhatsApp\Helpers;

class WhatsAppLogger
{
    private string $correlationId;

    public function __construct()
    {
        // Generate a unique Correlation / Request ID for this lifecycle trace
        $this->correlationId = 'waba_' . bin2hex(random_bytes(8));
    }

    /**
     * Set a custom correlation ID from incoming HTTP headers if tracing is active.
     */
    public function setCorrelationId(string $id): void
    {
        if (!empty($id)) {
            $this->correlationId = $id;
        }
    }

    public function getCorrelationId(): string
    {
        return $this->correlationId;
    }

    /**
     * Write a structured JSON log entry to standard error/out or Espo's log stream.
     */
    public function log(
        string $severity,
        string $message,
        array $context = []
    ): void {
        $logEntry = [
            'timestamp'      => date('c'),
            'severity'       => strtoupper($severity),
            'correlationId'  => $this->correlationId,
            'message'        => $message,
            'conversationId' => $context['conversationId'] ?? null,
            'campaignId'     => $context['campaignId'] ?? null,
            'agentId'        => $context['agentId'] ?? null,
            'executionTime'  => $context['executionTime'] ?? null,
            'retryCount'     => $context['retryCount'] ?? 0,
            'tenantId'       => $context['tenantId'] ?? 'default',
            'metadata'       => array_diff_key($context, array_flip([
                'conversationId', 'campaignId', 'agentId', 'executionTime', 'retryCount', 'tenantId'
            ]))
        ];

        // Format to single line JSON for Loki/Datadog log forwarders
        $jsonLine = json_encode($logEntry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        
        // Write to native error log (and Loki collectors reading standard err streams)
        error_log($jsonLine);
    }

    public function info(string $message, array $context = []): void
    {
        $this->log('INFO', $message, $context);
    }

    public function warning(string $message, array $context = []): void
    {
        $this->log('WARNING', $message, $context);
    }

    public function error(string $message, array $context = []): void
    {
        $this->log('ERROR', $message, $context);
    }

    public function debug(string $message, array $context = []): void
    {
        $this->log('DEBUG', $message, $context);
    }
}
