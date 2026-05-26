<?php
namespace Espo\Modules\WhatsApp\Services;

use Espo\Core\Config;
use Exception;

class MetaApiService
{
    private string $apiVersion = "v19.0";

    public function __construct(
        private Config $config
    ) {}

    /**
     * Sends a request to the Meta Cloud API.
     */
    private function request(string $endpoint, string $method = 'GET', ?array $data = null): array
    {
        $securityHelper = new \Espo\Modules\WhatsApp\Helpers\SecurityHelper($this->config);
        $tokenEncrypted = $this->config->get('whatsappMetaPermanentToken');
        $token = $securityHelper->decrypt($tokenEncrypted ?: '');

        if (empty($token)) {
            throw new Exception("WhatsApp Meta Permanent Access Token is not configured.");
        }

        $url = "https://graph.facebook.com/{$this->apiVersion}/{$endpoint}";
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        $headers = [
            "Authorization: Bearer {$token}",
            "Content-Type: application/json"
        ];

        if ($data !== null) {
            $payload = json_encode($data);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        }

        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new Exception("Meta API Connection failed: " . $error);
        }

        $result = json_decode($response, true);
        if ($httpCode >= 400) {
            $msg = $result['error']['message'] ?? "Unknown Meta API error (HTTP {$httpCode})";
            throw new Exception($msg);
        }

        return $result;
    }

    /**
     * Send a WhatsApp message.
     */
    public function sendMessage(string $to, string $type, array $content): array
    {
        $phoneId = $this->config->get('whatsappMetaPhoneNumberId');
        if (empty($phoneId)) {
            throw new Exception("WhatsApp Phone Number ID is not configured.");
        }

        $payload = [
            "messaging_product" => "whatsapp",
            "recipient_type" => "individual",
            "to" => $to,
            "type" => $type
        ];

        switch ($type) {
            case 'text':
                $payload['text'] = [
                    "preview_url" => $content['preview_url'] ?? false,
                    "body" => $content['body']
                ];
                break;
            case 'image':
                $payload['image'] = isset($content['id']) ? ["id" => $content['id']] : ["link" => $content['link']];
                if (isset($content['caption'])) {
                    $payload['image']['caption'] = $content['caption'];
                }
                break;
            case 'document':
                $payload['document'] = isset($content['id']) ? ["id" => $content['id']] : ["link" => $content['link']];
                if (isset($content['filename'])) {
                    $payload['document']['filename'] = $content['filename'];
                }
                if (isset($content['caption'])) {
                    $payload['document']['caption'] = $content['caption'];
                }
                break;
            case 'audio':
                $payload['audio'] = isset($content['id']) ? ["id" => $content['id']] : ["link" => $content['link']];
                break;
            case 'video':
                $payload['video'] = isset($content['id']) ? ["id" => $content['id']] : ["link" => $content['link']];
                if (isset($content['caption'])) {
                    $payload['video']['caption'] = $content['caption'];
                }
                break;
            case 'template':
                $payload['template'] = [
                    "name" => $content['name'],
                    "language" => ["code" => $content['language_code'] ?? 'en'],
                    "components" => $content['components'] ?? []
                ];
                break;
            default:
                throw new Exception("Unsupported WhatsApp message type: {$type}");
        }

        return $this->request("{$phoneId}/messages", 'POST', $payload);
    }

    /**
     * Sync approved WhatsApp templates from Meta.
     */
    public function syncTemplates(): array
    {
        $wabaId = $this->config->get('whatsappMetaWabaId');
        if (empty($wabaId)) {
            throw new Exception("WhatsApp WABA ID is not configured.");
        }

        $endpoint = "{$wabaId}/message_templates?limit=250";
        $response = $this->request($endpoint, 'GET');
        return $response['data'] ?? [];
    }

    /**
     * Download media binary data from Meta's servers.
     */
    public function downloadMedia(string $mediaId): array
    {
        // Step 1: Get media URL details from Meta
        $mediaDetails = $this->request($mediaId, 'GET');
        $url = $mediaDetails['url'] ?? null;
        if (empty($url)) {
            throw new Exception("Could not retrieve media download URL from Meta.");
        }

        $securityHelper = new \Espo\Modules\WhatsApp\Helpers\SecurityHelper($this->config);
        $tokenEncrypted = $this->config->get('whatsappMetaPermanentToken');
        $token = $securityHelper->decrypt($tokenEncrypted ?: '');
        
        // Step 2: Download media payload
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer {$token}"]);

        $binary = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || !$binary) {
            throw new Exception("Failed downloading media binary from Meta.");
        }

        return [
            'binary' => $binary,
            'mime_type' => $mediaDetails['mime_type'] ?? 'application/octet-stream',
            'file_size' => $mediaDetails['file_size'] ?? strlen($binary)
        ];
    }

    /**
     * Verify incoming webhook Hub challenge.
     */
    public function verifyWebhook(string $token, string $challenge): string
    {
        $savedToken = $this->config->get('whatsappMetaVerifyToken');
        if (empty($savedToken)) {
            throw new Exception("Meta Webhook Verify Token is not configured inside EspoCRM settings.");
        }

        if ($token === $savedToken) {
            return $challenge;
        }

        throw new Exception("Webhook verification token mismatch.");
    }

    /**
     * Validate webhook request signature.
     */
    public function validateSignature(string $payload, string $signatureHeader): bool
    {
        $securityHelper = new \Espo\Modules\WhatsApp\Helpers\SecurityHelper($this->config);
        $appSecretEncrypted = $this->config->get('whatsappMetaAppSecret');
        $appSecret = $securityHelper->decrypt($appSecretEncrypted ?: '');

        if (empty($appSecret)) {
            // If app secret is not configured yet, log warning and let it pass for development
            return true;
        }

        if (empty($signatureHeader)) {
            return false;
        }

        // Expected header format: sha256=HEX_SIGNATURE
        $parts = explode('=', $signatureHeader);
        if (count($parts) !== 2 || $parts[0] !== 'sha256') {
            return false;
        }

        $hash = hash_hmac('sha256', $payload, $appSecret);
        return hash_equals($parts[1], $hash);
    }
}
