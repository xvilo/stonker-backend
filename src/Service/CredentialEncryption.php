<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Authenticated symmetric encryption (libsodium secretbox) for broker
 * credentials at rest. The key is derived from the app secret, so the stored
 * Flex token/query id are never readable straight from the database.
 */
final class CredentialEncryption
{
    private string $key;

    public function __construct(#[Autowire('%kernel.secret%')] string $appSecret)
    {
        // Derive a 32-byte key from the app secret.
        $this->key = hash('sha256', 'stonker-credentials:'.$appSecret, true);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function encrypt(array $data): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = sodium_crypto_secretbox(json_encode($data, JSON_THROW_ON_ERROR), $nonce, $this->key);

        return base64_encode($nonce.$cipher);
    }

    /**
     * @return array<string, mixed>
     */
    public function decrypt(string $payload): array
    {
        $raw = base64_decode($payload, true);
        if (false === $raw || \strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new \RuntimeException('Malformed encrypted credentials.');
        }

        $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plain = sodium_crypto_secretbox_open($cipher, $nonce, $this->key);

        if (false === $plain) {
            throw new \RuntimeException('Could not decrypt credentials.');
        }

        return json_decode($plain, true, 512, JSON_THROW_ON_ERROR);
    }
}
