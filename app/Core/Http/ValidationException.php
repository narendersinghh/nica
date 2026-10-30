<?php

declare(strict_types=1);

namespace App\Core\Http;

final class ValidationException extends \RuntimeException
{
    /** Never flash these back into forms. */
    private const SENSITIVE = ['password', 'password_confirmation', 'current_password', 'password_current', 'code', 'token', '_token', 'card', 'cvc'];

    public function __construct(private array $errors, private Request $request)
    {
        parent::__construct('The given data was invalid.');
    }

    public function errors(): array
    {
        return $this->errors;
    }

    public function toResponse(): Response
    {
        if ($this->request->wantsJson()) {
            return Response::json(['error' => $this->getMessage(), 'errors' => $this->errors], 422);
        }
        $session = app()->session();
        $session->flash('_errors', $this->errors);
        $session->flash('_old', array_diff_key($this->request->post(), array_flip(self::SENSITIVE)));

        // Go back to the form, but only ever to a page on this site.
        $back = (string) $this->request->header('Referer', '');
        $base = app()->url()->base();
        $target = str_starts_with($back, $base . '/') ? $back : app()->url()->current();
        return Response::redirect($target, 303);
    }
}
