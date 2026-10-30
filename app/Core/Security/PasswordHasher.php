<?php

declare(strict_types=1);

namespace App\Core\Security;

/**
 * Argon2id for new hashes. Also verifies every format a WordPress/WooCommerce
 * database can contain, so imported users keep their passwords; those hashes
 * are upgraded to Argon2id on the next successful login (see needsRehash()).
 */
final class PasswordHasher
{
    private const ITOA64 = './0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';

    public static function hash(string $password): string
    {
        return defined('PASSWORD_ARGON2ID')
            ? password_hash($password, PASSWORD_ARGON2ID)
            : password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
    }

    public static function verify(string $password, ?string $hash): bool
    {
        if ($hash === null || $hash === '') {
            // Spend comparable time so missing accounts are not revealed by timing.
            self::hash($password);
            return false;
        }

        // WordPress 6.8+: "$wp" + bcrypt of base64(HMAC-SHA384(password)).
        if (str_starts_with($hash, '$wp$')) {
            $prehashed = base64_encode(hash_hmac('sha384', $password, 'wp-sha384', true));
            return password_verify($prehashed, substr($hash, 3));
        }

        // Classic WordPress phpass (portable): $P$ / $H$.
        if (str_starts_with($hash, '$P$') || str_starts_with($hash, '$H$')) {
            if (strlen($password) > 4096) {
                return false;
            }
            $computed = self::phpass($password, $hash);
            return $computed !== null && hash_equals($hash, $computed);
        }

        // Very old WordPress: plain MD5.
        if (strlen($hash) === 32 && ctype_xdigit($hash)) {
            return hash_equals(strtolower($hash), md5($password));
        }

        return password_verify($password, $hash);
    }

    public static function needsRehash(string $hash): bool
    {
        if (!str_starts_with($hash, '$argon2id$') && !str_starts_with($hash, '$2y$')) {
            return true; // legacy WordPress formats
        }
        return defined('PASSWORD_ARGON2ID')
            ? password_needs_rehash($hash, PASSWORD_ARGON2ID)
            : password_needs_rehash($hash, PASSWORD_BCRYPT, ['cost' => 12]);
    }

    private static function phpass(string $password, string $setting): ?string
    {
        $countLog2 = strpos(self::ITOA64, $setting[3]);
        if ($countLog2 === false || $countLog2 < 7 || $countLog2 > 30) {
            return null;
        }
        $count = 1 << $countLog2;
        $salt = substr($setting, 4, 8);
        if (strlen($salt) !== 8) {
            return null;
        }
        $hash = md5($salt . $password, true);
        do {
            $hash = md5($hash . $password, true);
        } while (--$count);

        return substr($setting, 0, 12) . self::encode64($hash, 16);
    }

    private static function encode64(string $input, int $count): string
    {
        $output = '';
        $i = 0;
        do {
            $value = ord($input[$i++]);
            $output .= self::ITOA64[$value & 0x3f];
            if ($i < $count) {
                $value |= ord($input[$i]) << 8;
            }
            $output .= self::ITOA64[($value >> 6) & 0x3f];
            if ($i++ >= $count) {
                break;
            }
            if ($i < $count) {
                $value |= ord($input[$i]) << 16;
            }
            $output .= self::ITOA64[($value >> 12) & 0x3f];
            if ($i++ >= $count) {
                break;
            }
            $output .= self::ITOA64[($value >> 18) & 0x3f];
        } while ($i < $count);

        return $output;
    }
}
