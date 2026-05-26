<?php
namespace Espo\Modules\WhatsApp\Helpers;

use Espo\Core\Config;

class SecurityHelper
{
    private string $cryptKey;
    private string $cipher = 'aes-256-cbc';

    public function __construct(Config $config)
    {
        // Leverage EspoCRM's native unique install cryptographic key
        $this->cryptKey = $config->get('cryptKey') ?: 'antigravity_fallback_salt_key_12345';
    }

    /**
     * Cryptographically encrypt a sensitive secret string.
     */
    public function encrypt(string $plainText): string
    {
        if (empty($plainText)) {
            return '';
        }

        // Generate a cryptographically secure pseudo-random initialization vector
        $ivLength = openssl_cipher_iv_length($this->cipher);
        $iv = random_bytes($ivLength);

        // Perform encryption
        $encrypted = openssl_encrypt($plainText, $this->cipher, $this->cryptKey, 0, $iv);

        // Prepend iv to the encrypted string (delimited with a dot) so it can be unpacked on decryption
        return base64_encode($iv . '::' . $encrypted);
    }

    /**
     * Decrypt an encrypted secret string back to cleartext.
     */
    public function decrypt(string $encryptedText): string
    {
        if (empty($encryptedText)) {
            return '';
        }

        $decoded = base64_decode($encryptedText, true);
        if ($decoded === false) {
            return $encryptedText; // fallback if already in cleartext
        }

        $parts = explode('::', $decoded, 2);
        if (count($parts) !== 2) {
            return $encryptedText; // fallback if formatting differs
        }

        list($iv, $encrypted) = $parts;
        $ivLength = openssl_cipher_iv_length($this->cipher);

        if (strlen($iv) !== $ivLength) {
            return $encryptedText;
        }

        $decrypted = openssl_decrypt($encrypted, $this->cipher, $this->cryptKey, 0, $iv);
        return $decrypted !== false ? $decrypted : '';
    }
}
