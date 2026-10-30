<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Loads config/*.php files lazily and resolves "file.key.sub" lookups.
 */
final class Config
{
    private array $items = [];

    public function __construct(private string $dir)
    {
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $parts = explode('.', $key);
        $file = array_shift($parts);
        if (!array_key_exists($file, $this->items)) {
            $path = $this->dir . '/' . $file . '.php';
            $this->items[$file] = is_file($path) ? require $path : [];
        }
        $value = $this->items[$file];
        foreach ($parts as $part) {
            if (!is_array($value) || !array_key_exists($part, $value)) {
                return $default;
            }
            $value = $value[$part];
        }
        return $value;
    }

    public function set(string $key, mixed $value): void
    {
        $this->get($key); // make sure the file is loaded
        $parts = explode('.', $key);
        $ref = &$this->items;
        foreach ($parts as $part) {
            if (!isset($ref[$part]) || !is_array($ref[$part])) {
                $ref[$part] = [];
            }
            $ref = &$ref[$part];
        }
        $ref = $value;
    }
}
