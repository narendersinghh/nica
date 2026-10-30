<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\App;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Http\ValidationException;
use App\Core\Validator;

abstract class Controller
{
    public function __construct(protected App $app)
    {
    }

    protected function view(string $name, array $data = [], int $status = 200): Response
    {
        return Response::html($this->app->view()->render($name, $data), $status);
    }

    protected function redirect(string $to, int $status = 302): Response
    {
        return Response::redirect($to, $status);
    }

    /**
     * Validate input. On failure, flashes errors + old input and throws a
     * redirect back (or a 422 JSON response for API calls).
     */
    protected function validate(Request $request, array $rules): array
    {
        $validator = new Validator($request->all(), $rules);
        if ($validator->passes()) {
            return $validator->validated();
        }
        throw new ValidationException($validator->errors(), $request);
    }
}
