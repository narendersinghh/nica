<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Admin-editable settings: values saved in the settings table override the
 * defaults in config/<group>.php. Read once per request.
 */
final class Settings
{
    private static array $cache = [];

    public static function get(string $key, mixed $default = null): mixed
    {
        [$group] = explode('.', $key, 2);
        if (!array_key_exists($group, self::$cache)) {
            $saved = null;
            try {
                $saved = app()->db()->scalar('SELECT value FROM settings WHERE name = ?', [$group]);
            } catch (\Throwable) {
                // database unavailable: config defaults only
            }
            self::$cache[$group] = array_replace_recursive((array) config($group, []), json_decode((string) $saved, true) ?: []);
        }
        $value = self::$cache[$group];
        foreach (array_slice(explode('.', $key), 1) as $part) {
            if (!is_array($value) || !array_key_exists($part, $value)) {
                return $default;
            }
            $value = $value[$part];
        }
        return $value;
    }

    public static function flush(): void
    {
        self::$cache = [];
    }
}
