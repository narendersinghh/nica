<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Http\Request;
use App\Core\Http\Response;

/** Shared by the vendor dashboard and the admin panel: flash notices, redirects, paging. */
trait PanelHelpers
{
    protected function notice(string $message): void
    {
        $this->app->session()->flash('panel_notice', $message);
    }

    protected function error(string $message): void
    {
        $this->app->session()->flash('panel_error', $message);
    }

    protected function back(string $path): Response
    {
        return $this->redirect(link_to($path), 303);
    }

    /** Page number from the route or ?paged=. */
    protected function page(Request $request): int
    {
        return max(1, (int) ($request->param('page') ?? $request->query('paged', 1)));
    }

    /** Escape a LIKE pattern fragment. */
    protected static function like(string $value): string
    {
        return '%' . str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $value) . '%';
    }
}
