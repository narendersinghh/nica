<?php

declare(strict_types=1);

namespace App\Core\Http;

interface Middleware
{
    /**
     * @param \Closure(Request):Response $next
     * @param string[] $args Arguments from the route definition, e.g. "role:admin,vendor".
     */
    public function handle(Request $request, \Closure $next, array $args = []): Response;
}
