<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Rules: required, nullable, string, email, numeric, integer, boolean, min:n, max:n,
 * between:a,b, in:a,b,c, regex:/.../, confirmed, slug, url, array.
 * Only fields with rules end up in validated().
 */
final class Validator
{
    private array $errors = [];
    private array $validated = [];
    private bool $ran = false;

    public function __construct(private array $data, private array $rules)
    {
    }

    public function passes(): bool
    {
        $this->run();
        return $this->errors === [];
    }

    public function errors(): array
    {
        $this->run();
        return $this->errors;
    }

    public function validated(): array
    {
        $this->run();
        return $this->validated;
    }

    private function run(): void
    {
        if ($this->ran) {
            return;
        }
        $this->ran = true;

        foreach ($this->rules as $field => $rules) {
            $rules = is_array($rules) ? $rules : explode('|', $rules);
            $value = $this->data[$field] ?? null;
            if (is_string($value)) {
                $value = trim($value);
            }
            $label = str_replace('_', ' ', $field);
            $empty = $value === null || $value === '' || $value === [];

            if (in_array('required', $rules, true) && $empty) {
                $this->errors[$field] = ucfirst($label) . ' is required.';
                continue;
            }
            if ($empty) {
                if (in_array('nullable', $rules, true) || !in_array('required', $rules, true)) {
                    $this->validated[$field] = in_array('boolean', $rules, true) ? false : null;
                }
                continue;
            }
            $numeric = in_array('numeric', $rules, true) || in_array('integer', $rules, true);

            foreach ($rules as $rule) {
                [$name, $arg] = array_pad(explode(':', $rule, 2), 2, null);
                $error = match ($name) {
                    'required', 'nullable' => null,
                    'string' => is_string($value) ? null : 'must be text.',
                    'array' => is_array($value) ? null : 'is invalid.',
                    'email' => is_string($value) && filter_var($value, FILTER_VALIDATE_EMAIL) && strlen($value) <= 191 ? null : 'must be a valid email address.',
                    'numeric' => is_numeric($value) ? null : 'must be a number.',
                    'integer' => filter_var($value, FILTER_VALIDATE_INT) !== false ? null : 'must be a whole number.',
                    'boolean' => in_array($value, [true, false, 0, 1, '0', '1', 'on', 'yes'], true) ? null : 'is invalid.',
                    'min' => $this->size($value, $numeric) >= (float) $arg ? null : ($numeric ? "must be at least {$arg}." : "must be at least {$arg} characters."),
                    'max' => $this->size($value, $numeric) <= (float) $arg ? null : ($numeric ? "may not be greater than {$arg}." : "may not be longer than {$arg} characters."),
                    'between' => (function () use ($value, $arg, $numeric) {
                        [$lo, $hi] = array_map('floatval', explode(',', (string) $arg));
                        $size = $this->size($value, $numeric);
                        return $size >= $lo && $size <= $hi ? null : "must be between {$lo} and {$hi}.";
                    })(),
                    'in' => is_scalar($value) && in_array((string) $value, explode(',', (string) $arg), true) ? null : 'is invalid.',
                    'regex' => is_string($value) && preg_match((string) $arg, $value) ? null : 'has an invalid format.',
                    'slug' => is_string($value) && preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $value) ? null : 'may only contain lowercase letters, numbers and dashes.',
                    'url' => is_string($value) && filter_var($value, FILTER_VALIDATE_URL) && preg_match('#^https?://#i', $value) ? null : 'must be a valid URL.',
                    'confirmed' => ($this->data[$field . '_confirmation'] ?? null) === ($this->data[$field] ?? null) ? null : 'confirmation does not match.',
                    default => throw new \InvalidArgumentException("Unknown validation rule '{$name}'."),
                };
                if ($error !== null) {
                    $this->errors[$field] = ucfirst($label) . ' ' . $error;
                    continue 2;
                }
            }

            $this->validated[$field] = match (true) {
                in_array('integer', $rules, true) => (int) $value,
                in_array('boolean', $rules, true) => in_array($value, [true, 1, '1', 'on', 'yes'], true),
                default => $value,
            };
        }
    }

    private function size(mixed $value, bool $numeric): float
    {
        return match (true) {
            is_array($value) => count($value),
            $numeric && is_numeric($value) => (float) $value,
            default => str_length((string) $value),
        };
    }
}
