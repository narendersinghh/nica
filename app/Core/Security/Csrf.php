<?php

declare(strict_types=1);

namespace App\Core\Security;

use App\Core\Http\Request;

/** Synchronizer-token CSRF protection plus an Origin check. */
final class Csrf
{
    public function __construct(private Session $session)
    {
    }

    public function token(): string
    {
        $token = $this->session->get('_csrf');
        if (!is_string($token) || strlen($token) !== 64) {
            $token = bin2hex(random_bytes(32));
            $this->session->set('_csrf', $token);
        }
        return $token;
    }

    public function rotate(): void
    {
        $this->session->forget('_csrf');
    }

    public function verify(Request $request, string $expectedHost): bool
    {
        // A cross-site Origin is rejected outright, before the token is even looked at.
        $origin = $request->header('Origin');
        if ($origin !== null && $origin !== '' && $origin !== 'null') {
            $originHost = strtolower((string) parse_url($origin, PHP_URL_HOST));
            $port = parse_url($origin, PHP_URL_PORT);
            if ($originHost . ($port ? ':' . $port : '') !== strtolower($expectedHost)) {
                return false;
            }
        }

        $sent = $request->post('_token') ?? $request->header('X-CSRF-Token');
        $stored = $this->session->get('_csrf');
        return is_string($sent) && is_string($stored) && $stored !== '' && hash_equals($stored, $sent);
    }
}
