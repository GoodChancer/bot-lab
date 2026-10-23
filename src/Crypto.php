<?php
declare(strict_types=1);

namespace Studio;

/** Encrypts secrets (Slack tokens, signing secrets) before they go in the database. */
final class Crypto
{
    public static function newKey(): string
    {
        return base64_encode(sodium_crypto_secretbox_keygen());
    }

    public static function encrypt(string $plain): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, self::key()));
    }

    public static function decrypt(?string $stored): string
    {
        if ($stored === null || $stored === '') {
            return '';
        }
        $raw = base64_decode($stored, true);
        if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new \RuntimeException('Stored secret is damaged.');
        }
        $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plain = sodium_crypto_secretbox_open(substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $nonce, self::key());
        if ($plain === false) {
            throw new \RuntimeException('Could not decrypt a stored secret. Did secret_key in config.php change?');
        }
        return $plain;
    }

    private static function key(): string
    {
        $key = base64_decode((string) Config::get('secret_key'), true);
        if ($key === false || strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new \RuntimeException('secret_key in config.php is missing or invalid. Run: php bin/setup.php');
        }
        return $key;
    }
}
