<?php

declare(strict_types=1);

namespace App\Services;

/** Append-only record of sensitive actions (who, what, on which record, from where). */
final class Audit
{
    public static function log(string $action, ?string $subjectType = null, ?int $subjectId = null, array $meta = []): void
    {
        $app = app();
        $request = $app->request();
        $actor = $app->auth()->id();
        if ($impersonator = $app->session()->get('impersonator_id')) {
            $meta['impersonated_by'] = (int) $impersonator;
        }
        $app->db()->insert('audit_log', [
            'user_id' => $actor,
            'action' => mb_substr_safe($action, 100),
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'meta' => $meta ? json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            'ip' => $request->ip() ?: null,
            'user_agent' => mb_substr_safe($request->userAgent(), 255) ?: null,
        ]);
    }
}
