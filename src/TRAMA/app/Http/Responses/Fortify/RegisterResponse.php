<?php

namespace App\Http\Responses\Fortify;

use Illuminate\Http\JsonResponse;
use Laravel\Fortify\Contracts\RegisterResponse as RegisterResponseContract;

class RegisterResponse implements RegisterResponseContract
{
    /**
     * Returns readers to the requested local article or the public homepage.
     */
    public function toResponse($request)
    {
        if ($request->wantsJson()) {
            return new JsonResponse('', 201);
        }

        $redirect = $this->safeRedirectPath($request->input('redirect'));

        return $redirect ? redirect($redirect) : redirect()->route('home');
    }

    /**
     * Allows only local paths and rejects external redirects.
     */
    private function safeRedirectPath(mixed $value): ?string
    {
        if (! is_string($value) || $value === '' || ! str_starts_with($value, '/') || str_starts_with($value, '//')) {
            return null;
        }

        return $value;
    }
}
