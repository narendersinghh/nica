<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Plain-PHP templates. Inside a template `$this` is a Template with
 * layout(), start()/stop(), section(), insert() and e().
 */
final class View
{
    private array $shared = [];

    public function __construct(private string $dir)
    {
    }

    public function share(string $key, mixed $value): void
    {
        $this->shared[$key] = $value;
    }

    public function render(string $name, array $data = []): string
    {
        $template = new Template($this, array_merge($this->shared, $data));
        return $template->render($name);
    }

    public function file(string $name): string
    {
        if (!preg_match('#^[a-z0-9_-]+(/[a-z0-9_-]+)*$#i', $name)) {
            throw new \InvalidArgumentException("Invalid view name '{$name}'.");
        }
        $file = $this->dir . '/' . $name . '.php';
        if (!is_file($file)) {
            throw new \RuntimeException("View '{$name}' not found.");
        }
        return $file;
    }
}
