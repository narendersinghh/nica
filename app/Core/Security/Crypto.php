<?php

declare(strict_types=1);

namespace App\Core\Security;

/** Authenticated encryption (libsodium secretbox) with per-purpose keys derived from APP_KEY. */
final class Crypto
{
    public static function encrypt(string $plain, string $purpose): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return $nonce . sodium_crypto_secretbox($plain, $nonce, self::key($purpose));
    }

    public static function decrypt(?string $blob, string $purpose): ?string
    {
        if ($blob === null || strlen($blob) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return null;
        }
        $plain = sodium_crypto_secretbox_open(substr($blob, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), substr($blob, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), self::key($purpose));
        return $plain === false ? null : $plain;
    }

    private static function key(string $purpose): string
    {
        $appKey = (string) config('app.key');
        if (strlen($appKey) < 32) {
            throw new \RuntimeException('APP_KEY must be set (php bin/console key:generate).');
        }
        return sodium_crypto_generichash($purpose, $appKey, SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    }
}
