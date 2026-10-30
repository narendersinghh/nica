<?php

declare(strict_types=1);

namespace App\Core\Security;

/**
 * TOTP (RFC 6238: SHA-1, 30 s, 6 digits) with the secret encrypted at rest
 * using libsodium and a key derived from APP_KEY.
 */
final class TwoFactor
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public static function generateSecret(): string
    {
        $bytes = random_bytes(20);
        $bits = '';
        foreach (str_split($bytes) as $b) {
            $bits .= str_pad(decbin(ord($b)), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::ALPHABET[bindec(str_pad($chunk, 5, '0'))];
        }
        return $out;
    }

    public static function verify(string $secret, string $code, ?int $time = null, int $window = 1): bool
    {
        $code = preg_replace('/\s+/', '', $code);
        if (!preg_match('/^\d{6}$/', (string) $code)) {
            return false;
        }
        $step = intdiv($time ?? time(), 30);
        for ($i = -$window; $i <= $window; $i++) {
            if (hash_equals(self::code($secret, $step + $i), $code)) {
                return true;
            }
        }
        return false;
    }

    public static function code(string $secret, int $step): string
    {
        $key = self::base32Decode($secret);
        $hash = hash_hmac('sha1', pack('N*', 0) . pack('N*', $step), $key, true);
        $offset = ord($hash[19]) & 0xf;
        $value = ((ord($hash[$offset]) & 0x7f) << 24) | ((ord($hash[$offset + 1]) & 0xff) << 16) | ((ord($hash[$offset + 2]) & 0xff) << 8) | (ord($hash[$offset + 3]) & 0xff);
        return str_pad((string) ($value % 1_000_000), 6, '0', STR_PAD_LEFT);
    }

    public static function otpauthUrl(string $secret, string $account, string $issuer): string
    {
        return 'otpauth://totp/' . rawurlencode($issuer . ':' . $account) . '?' . http_build_query(['secret' => $secret, 'issuer' => $issuer, 'algorithm' => 'SHA1', 'digits' => 6, 'period' => 30]);
    }

    /** @return string[] plain recovery codes (shown once) */
    public static function recoveryCodes(int $count = 8): array
    {
        return array_map(static fn () => implode('-', str_split(bin2hex(random_bytes(5)), 5)), range(1, $count));
    }

    public static function encrypt(string $secret): string
    {
        return Crypto::encrypt($secret, 'nica-2fa');
    }

    public static function decrypt(string $blob): ?string
    {
        return Crypto::decrypt($blob, 'nica-2fa');
    }

    private static function base32Decode(string $s): string
    {
        $s = strtoupper(rtrim($s, '='));
        $bits = '';
        foreach (str_split($s) as $c) {
            $pos = strpos(self::ALPHABET, $c);
            if ($pos === false) {
                continue;
            }
            $bits .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $out .= chr(bindec($byte));
            }
        }
        return $out;
    }
}
