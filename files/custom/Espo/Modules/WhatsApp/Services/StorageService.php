<?php
namespace Espo\Modules\WhatsApp\Services;

use Espo\Core\Config;
use Exception;

class StorageService
{
    private string $uploadDir;

    public function __construct(
        private Config $config
    ) {
        // Set local uploads path
        $this->uploadDir = dirname(dirname(dirname(dirname(dirname(__DIR__))))) . '/data/upload/whatsapp_media/';
        if (!is_dir($this->uploadDir)) {
            @mkdir($this->uploadDir, 0775, true);
        }
    }

    /**
     * Store file from binary data.
     * Returns local path or S3 key.
     */
    public function storeMedia(string $binary, string $filename, string $mimeType): string
    {
        $extension = $this->getExtensionFromMime($mimeType);
        $uniqueName = uniqid('wa_', true) . ($extension ? '.' . $extension : '');
        $subFolder = date('Y/m');
        
        $s3Enabled = $this->config->get('whatsappS3Enabled');
        if ($s3Enabled) {
            return $this->uploadToS3($binary, "{$subFolder}/{$uniqueName}", $mimeType);
        }

        // Local Storage
        $targetDir = $this->uploadDir . $subFolder;
        if (!is_dir($targetDir)) {
            @mkdir($targetDir, 0775, true);
        }

        $targetPath = $targetDir . '/' . $uniqueName;
        if (file_put_contents($targetPath, $binary) === false) {
            throw new Exception("Failed to write WhatsApp media file locally.");
        }

        return "local://{$subFolder}/{$uniqueName}";
    }

    /**
     * Delete stored media (supports GDPR data deletion rules).
     */
    public function deleteMedia(string $path): bool
    {
        if (strpos($path, 'local://') === 0) {
            $relativePath = substr($path, 8);
            $fullPath = $this->uploadDir . $relativePath;
            if (file_exists($fullPath)) {
                return @unlink($fullPath);
            }
        } elseif (strpos($path, 's3://') === 0) {
            return $this->deleteFromS3($path);
        }
        return false;
    }

    /**
     * Get path/binary stream of media.
     */
    public function getMediaBinary(string $path): ?string
    {
        if (strpos($path, 'local://') === 0) {
            $relativePath = substr($path, 8);
            $fullPath = $this->uploadDir . $relativePath;
            if (file_exists($fullPath)) {
                return file_get_contents($fullPath);
            }
        } elseif (strpos($path, 's3://') === 0) {
            return $this->downloadFromS3($path);
        }
        return null;
    }

    /**
     * Get absolute absolute file path for local serving.
     */
    public function getLocalPath(string $path): ?string
    {
        if (strpos($path, 'local://') === 0) {
            return $this->uploadDir . substr($path, 8);
        }
        return null;
    }

    /**
     * Mock upload to AWS S3 (or S3-compatible like MinIO/DigitalOcean Spaces).
     * Implementing native PHP S3 REST upload without bloating dependencies.
     */
    private function uploadToS3(string $binary, string $key, string $mimeType): string
    {
        $bucket = $this->config->get('whatsappS3Bucket');
        $region = $this->config->get('whatsappS3Region') ?: 'us-east-1';

        $securityHelper = new \Espo\Modules\WhatsApp\Helpers\SecurityHelper($this->config);
        $accessKey = $securityHelper->decrypt($this->config->get('whatsappS3AccessKey') ?: '');
        $secretKey = $securityHelper->decrypt($this->config->get('whatsappS3SecretKey') ?: '');

        if (empty($bucket) || empty($accessKey) || empty($secretKey)) {
            // Fallback to local storage if S3 configured poorly
            $targetDir = $this->uploadDir . dirname($key);
            if (!is_dir($targetDir)) {
                @mkdir($targetDir, 0775, true);
            }
            $targetPath = $this->uploadDir . $key;
            file_put_contents($targetPath, $binary);
            return "local://{$key}";
        }

        // Standard AWS S3 PUT Headers & HMAC Signature v4
        $host = "{$bucket}.s3.{$region}.amazonaws.com";
        $service = 's3';
        $algorithm = 'AWS4-HMAC-SHA256';
        $amzDate = gmdate('Ymd\THis\Z');
        $date = substr($amzDate, 0, 8);

        $canonicalUri = '/' . ltrim($key, '/');
        $canonicalQueryString = '';
        $canonicalHeaders = "host:{$host}\nx-amz-content-sha256:" . hash('sha256', $binary) . "\nx-amz-date:{$amzDate}\n";
        $signedHeaders = 'host;x-amz-content-sha256;x-amz-date';

        $stringToSign = "{$algorithm}\n{$amzDate}\n{$date}/{$region}/{$service}/aws4_request\n" . hash('sha256', "PUT\n{$canonicalUri}\n{$canonicalQueryString}\n{$canonicalHeaders}\n{$signedHeaders}\n" . hash('sha256', $binary));

        $kDate = hash_hmac('sha256', $date, "AWS4" . $secretKey, true);
        $kRegion = hash_hmac('sha256', $region, $kDate, true);
        $kService = hash_hmac('sha256', $service, $kRegion, true);
        $kSigning = hash_hmac('sha256', "aws4_request", $kService, true);
        $signature = hash_hmac('sha256', $stringToSign, $kSigning);

        $authorizationHeader = "{$algorithm} Credential={$accessKey}/{$date}/{$region}/{$service}/aws4_request, SignedHeaders={$signedHeaders}, Signature={$signature}";

        $url = "https://{$host}{$canonicalUri}";

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
        curl_setopt($ch, CURLOPT_POSTFIELDS, $binary);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Host: {$host}",
            "x-amz-date: {$amzDate}",
            "x-amz-content-sha256: " . hash('sha256', $binary),
            "Content-Type: {$mimeType}",
            "Authorization: {$authorizationHeader}"
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200) {
            return "s3://{$key}";
        }

        // Fallback to local
        $targetDir = $this->uploadDir . dirname($key);
        if (!is_dir($targetDir)) {
            @mkdir($targetDir, 0775, true);
        }
        file_put_contents($this->uploadDir . $key, $binary);
        return "local://{$key}";
    }

    private function deleteFromS3(string $path): bool
    {
        $key = substr($path, 5);
        $bucket = $this->config->get('whatsappS3Bucket');
        $region = $this->config->get('whatsappS3Region') ?: 'us-east-1';

        $securityHelper = new \Espo\Modules\WhatsApp\Helpers\SecurityHelper($this->config);
        $accessKey = $securityHelper->decrypt($this->config->get('whatsappS3AccessKey') ?: '');
        $secretKey = $securityHelper->decrypt($this->config->get('whatsappS3SecretKey') ?: '');

        if (empty($bucket) || empty($accessKey) || empty($secretKey)) {
            return false;
        }

        $host = "{$bucket}.s3.{$region}.amazonaws.com";
        $service = 's3';
        $algorithm = 'AWS4-HMAC-SHA256';
        $amzDate = gmdate('Ymd\THis\Z');
        $date = substr($amzDate, 0, 8);

        $canonicalUri = '/' . ltrim($key, '/');
        $canonicalQueryString = '';
        $canonicalHeaders = "host:{$host}\nx-amz-content-sha256:" . hash('sha256', '') . "\nx-amz-date:{$amzDate}\n";
        $signedHeaders = 'host;x-amz-content-sha256;x-amz-date';

        $stringToSign = "{$algorithm}\n{$amzDate}\n{$date}/{$region}/{$service}/aws4_request\n" . hash('sha256', "DELETE\n{$canonicalUri}\n{$canonicalQueryString}\n{$canonicalHeaders}\n{$signedHeaders}\n" . hash('sha256', ''));

        $kDate = hash_hmac('sha256', $date, "AWS4" . $secretKey, true);
        $kRegion = hash_hmac('sha256', $region, $kDate, true);
        $kService = hash_hmac('sha256', $service, $kRegion, true);
        $kSigning = hash_hmac('sha256', "aws4_request", $kService, true);
        $signature = hash_hmac('sha256', $stringToSign, $kSigning);

        $authorizationHeader = "{$algorithm} Credential={$accessKey}/{$date}/{$region}/{$service}/aws4_request, SignedHeaders={$signedHeaders}, Signature={$signature}";

        $url = "https://{$host}{$canonicalUri}";

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Host: {$host}",
            "x-amz-date: {$amzDate}",
            "x-amz-content-sha256: " . hash('sha256', ''),
            "Authorization: {$authorizationHeader}"
        ]);

        curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return ($httpCode === 204 || $httpCode === 200);
    }

    private function downloadFromS3(string $path): ?string
    {
        $key = substr($path, 5);
        $bucket = $this->config->get('whatsappS3Bucket');
        $region = $this->config->get('whatsappS3Region') ?: 'us-east-1';

        $securityHelper = new \Espo\Modules\WhatsApp\Helpers\SecurityHelper($this->config);
        $accessKey = $securityHelper->decrypt($this->config->get('whatsappS3AccessKey') ?: '');
        $secretKey = $securityHelper->decrypt($this->config->get('whatsappS3SecretKey') ?: '');

        if (empty($bucket) || empty($accessKey) || empty($secretKey)) {
            return null;
        }

        $host = "{$bucket}.s3.{$region}.amazonaws.com";
        $service = 's3';
        $algorithm = 'AWS4-HMAC-SHA256';
        $amzDate = gmdate('Ymd\THis\Z');
        $date = substr($amzDate, 0, 8);

        $canonicalUri = '/' . ltrim($key, '/');
        $canonicalQueryString = '';
        $canonicalHeaders = "host:{$host}\nx-amz-content-sha256:" . hash('sha256', '') . "\nx-amz-date:{$amzDate}\n";
        $signedHeaders = 'host;x-amz-content-sha256;x-amz-date';

        $stringToSign = "{$algorithm}\n{$amzDate}\n{$date}/{$region}/{$service}/aws4_request\n" . hash('sha256', "GET\n{$canonicalUri}\n{$canonicalQueryString}\n{$canonicalHeaders}\n{$signedHeaders}\n" . hash('sha256', ''));

        $kDate = hash_hmac('sha256', $date, "AWS4" . $secretKey, true);
        $kRegion = hash_hmac('sha256', $region, $kDate, true);
        $kService = hash_hmac('sha256', $service, $kRegion, true);
        $kSigning = hash_hmac('sha256', "aws4_request", $kService, true);
        $signature = hash_hmac('sha256', $stringToSign, $kSigning);

        $authorizationHeader = "{$algorithm} Credential={$accessKey}/{$date}/{$region}/{$service}/aws4_request, SignedHeaders={$signedHeaders}, Signature={$signature}";

        $url = "https://{$host}{$canonicalUri}";

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Host: {$host}",
            "x-amz-date: {$amzDate}",
            "x-amz-content-sha256: " . hash('sha256', ''),
            "Authorization: {$authorizationHeader}"
        ]);

        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return ($httpCode === 200) ? $result : null;
    }

    private function getExtensionFromMime(string $mimeType): ?string
    {
        $map = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'application/pdf' => 'pdf',
            'application/msword' => 'doc',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
            'audio/mpeg' => 'mp3',
            'audio/ogg' => 'ogg',
            'audio/aac' => 'aac',
            'audio/amr' => 'amr',
            'video/mp4' => 'mp4',
            'video/3gpp' => '3gp'
        ];
        return $map[$mimeType] ?? null;
    }
}
