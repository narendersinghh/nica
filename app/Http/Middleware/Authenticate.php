<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Core\Http\HttpException;
use App\Core\Http\Middleware;
use App\Core\Http\Request;
use App\Core\Http\Response;

final class Authenticate implements Middleware
{
    public function handle(Request $request, \Closure $next, array $args = []): Response
    {
        if (app()->auth()->check()) {
            return $next($request);
        }
        if ($request->wantsJson()) {
            throw new HttpException(401);
        }
        if ($request->isMethod('GET')) {
            app()->session()->set('url.intended', $request->uri());
        }
        return Response::redirect('/my-account/');
    }
}
