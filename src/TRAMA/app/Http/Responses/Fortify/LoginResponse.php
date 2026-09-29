<?php

namespace App\Http\Responses\Fortify;

use App\Support\TramaClock;
use Illuminate\Http\JsonResponse;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;

class LoginResponse implements LoginResponseContract
{
    /**
     * Redirects authenticated users to the correct TRAMA surface.
     */
    public function toResponse($request)
    {
        $user = $request->user();

        if ($user) {
            $user->forceFill(['last_login_at' => TramaClock::now()])->save();
        }

        if ($request->wantsJson()) {
            return new JsonResponse(['two_factor' => false]);
        }

        if ($user?->canAccessEditorial()) {
            return redirect()->route('admin.dashboard');
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
