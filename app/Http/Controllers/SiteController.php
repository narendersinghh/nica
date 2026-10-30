<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Http\Request;
use App\Core\Http\Response;

final class SiteController extends Controller
{
    /** Phase 0 placeholder; replaced by the rebuilt homepage in Phase 1. */
    public function home(Request $request): Response
    {
        return $this->view('site/home', [
            'title' => 'The NiCa Collection',
        ]);
    }
}
