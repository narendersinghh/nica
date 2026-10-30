<?php

declare(strict_types=1);

namespace App\Core;

final class Template
{
    private ?string $layout = null;
    private array $layoutData = [];
    private array $sections = [];
    private array $sectionStack = [];

    public function __construct(private View $view, private array $data)
    {
    }

    public function render(string $name): string
    {
        $content = $this->capture($this->view->file($name), $this->data);

        // Layouts may themselves extend another layout.
        while ($this->layout !== null) {
            $layout = $this->layout;
            $this->layout = null;
            // Each layout level wraps the output of the level below it.
            $this->sections['content'] = $content;
            $this->data = array_merge($this->data, $this->layoutData);
            $content = $this->capture($this->view->file($layout), $this->data);
        }
        return $content;
    }

    public function layout(string $name, array $data = []): void
    {
        $this->layout = $name;
        $this->layoutData = $data;
    }

    public function start(string $name): void
    {
        $this->sectionStack[] = $name;
        ob_start();
    }

    public function stop(): void
    {
        $name = array_pop($this->sectionStack);
        if ($name === null) {
            throw new \LogicException('stop() called without start().');
        }
        $this->sections[$name] = ob_get_clean();
    }

    public function section(string $name, string $default = ''): string
    {
        return $this->sections[$name] ?? $default;
    }

    /** Render a partial with the parent's data plus $data. */
    public function insert(string $name, array $data = []): string
    {
        return (new Template($this->view, array_merge($this->data, $data)))->render($name);
    }

    public function e(mixed $value): string
    {
        return e($value);
    }

    private function capture(string $__file, array $__data): string
    {
        extract($__data, EXTR_SKIP);
        $level = ob_get_level();
        ob_start();
        try {
            include $__file;
        } catch (\Throwable $e) {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
            throw $e;
        }
        return (string) ob_get_clean();
    }
}
