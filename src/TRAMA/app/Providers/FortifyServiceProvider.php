<?php

/* ============================================================================
 * PROVIDER: FortifyServiceProvider.php
 * ============================================================================
 *
 * Configura la autenticación Fortify de TRAMA.
 *
 * Define qué clases crean usuarios, actualizan perfil, cambian contraseña y
 * restablecen acceso. También reemplaza las vistas default de Fortify por
 * pantallas Vue/Inertia propias, valida cuentas activas y verificadas durante
 * el login, y configura límites de intentos para proteger formularios.
 * ============================================================================ */

namespace App\Providers;

use App\Actions\Fortify\CreateNewUser;
use App\Actions\Fortify\ResetUserPassword;
use App\Actions\Fortify\UpdateUserPassword;
use App\Actions\Fortify\UpdateUserProfileInformation;
use App\Http\Responses\Fortify\FailedPasswordResetLinkRequestResponse as TramaFailedPasswordResetLinkRequestResponse;
use App\Http\Responses\Fortify\LoginResponse as TramaLoginResponse;
use App\Http\Responses\Fortify\RegisterResponse as TramaRegisterResponse;
use App\Models\User;
use App\Support\TramaBridge;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\FailedPasswordResetLinkRequestResponse as FailedPasswordResetLinkRequestResponseContract;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;
use Laravel\Fortify\Contracts\RegisterResponse as RegisterResponseContract;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
{
    /**
     * Registra respuestas personalizadas para login y registro.
     */
    public function register(): void
    {
        // Después de iniciar sesión, TRAMA decide manualmente a qué pantalla volver.
        $this->app->singleton(LoginResponseContract::class, TramaLoginResponse::class);
        // Después de registrarse, TRAMA mantiene el flujo de verificación por correo.
        $this->app->singleton(RegisterResponseContract::class, TramaRegisterResponse::class);
        // Password recovery should not reveal whether the email exists.
        $this->app->singleton(FailedPasswordResetLinkRequestResponseContract::class, TramaFailedPasswordResetLinkRequestResponse::class);
    }

    /**
     * Configura acciones, pantallas, validación de login y límites de Fortify.
     */
    public function boot(): void
    {
        // Acciones que Fortify ejecuta cuando el usuario usa formularios de cuenta.
        Fortify::createUsersUsing(CreateNewUser::class);
        Fortify::updateUserProfileInformationUsing(UpdateUserProfileInformation::class);
        Fortify::updateUserPasswordsUsing(UpdateUserPassword::class);
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);

        // Pantalla Vue/Inertia para iniciar sesión.
        Fortify::loginView(fn (Request $request) => TramaBridge::render('Auth/Login', [
            // Permite mostrar el enlace "Olvidé mi contraseña" solo si la ruta existe.
            'canResetPassword' => Route::has('password.request'),
            // Mensaje flash enviado por Laravel, por ejemplo tras resetear contraseña.
            'status' => session('status'),
            // Mensaje específico cuando una cuenta bloqueada fue expulsada por el middleware.
            'accountBlocked' => session('account_blocked'),
            // Ruta interna a la que se vuelve después de iniciar sesión.
            'redirect' => $this->safeRedirectPath($request->query('redirect')),
        ]));

        // Pantalla Vue/Inertia para crear una cuenta pública.
        Fortify::registerView(fn (Request $request) => TramaBridge::render('Auth/Register', [
            // Conserva una redirección local, por ejemplo volver a una noticia.
            'redirect' => $this->safeRedirectPath($request->query('redirect')),
        ]));

        // Pantalla donde el usuario pide el enlace para recuperar contraseña.
        Fortify::requestPasswordResetLinkView(fn () => TramaBridge::render('Auth/ForgotPassword', [
            'status' => session('status'),
        ]));

        // Pantalla donde el usuario define nueva contraseña usando token.
        Fortify::resetPasswordView(fn (Request $request) => TramaBridge::render('Auth/ResetPassword', [
            'email' => $request->email,
            'token' => $request->route('token'),
            'resetLinkError' => $this->resetLinkError($request),
        ]));

        // Pantalla informativa que pide verificar el correo.
        Fortify::verifyEmailView(fn () => TramaBridge::render('Auth/VerifyEmail', [
            'status' => session('status'),
        ]));

        // Pantalla usada por acciones sensibles que piden reingresar contraseña.
        Fortify::confirmPasswordView(fn () => TramaBridge::render('Auth/ConfirmPassword'));

        // Validación personalizada de login: además de email y contraseña,
        // comprueba cuenta activa, reset obligatorio y correo verificado.
        Fortify::authenticateUsing(function (Request $request): ?User {
            $user = User::query()
                // Fortify::username normalmente devuelve "email".
                ->where('email', $request->input(Fortify::username()))
                ->first();

            if (! $user || ! Hash::check((string) $request->input('password'), $user->password)) {
                // null hace que Fortify devuelva el error normal de credenciales.
                return null;
            }

            if (! $user->is_active) {
                // Cuenta bloqueada desde el panel administrativo.
                throw ValidationException::withMessages([
                    Fortify::username() => 'Tu cuenta se encuentra bloqueada y no puede acceder a TRAMA. Si considerás que se trata de un error, contactá al equipo de TRAMA.',
                ]);
            }

            if ($user->password_reset_required_at !== null) {
                // Cuenta marcada para restablecer acceso desde un enlace enviado por email.
                throw ValidationException::withMessages([
                    Fortify::username() => 'Debés restablecer la contraseña desde el enlace enviado a tu correo.',
                ]);
            }

            if ($user instanceof MustVerifyEmail && ! $user->hasVerifiedEmail()) {
                // No se permite login hasta confirmar el enlace de verificación.
                throw ValidationException::withMessages([
                    Fortify::username() => __('auth.email_not_verified'),
                ]);
            }

            if (Hash::needsRehash($user->password)) {
                // Si Laravel cambió el algoritmo de hash, se actualiza al iniciar sesión.
                $user->forceFill(['password' => Hash::make((string) $request->input('password'))])->save();
            }

            return $user;
        });

        // Límite de intentos para login normal.
        RateLimiter::for('login', function (Request $request) {
            // Combina email normalizado e IP para limitar ataques por cuenta.
            $throttleKey = Str::transliterate(Str::lower($request->input(Fortify::username())).'|'.$request->ip());

            return Limit::perMinute(5)->by($throttleKey);
        });

        // Límite para segundo factor, aunque TRAMA no lo exponga como flujo principal.
        RateLimiter::for('two-factor', function (Request $request) {
            return Limit::perMinute(5)->by($request->session()->get('login.id'));
        });

        // Límite para passkeys si Fortify recibe peticiones de credenciales modernas.
        RateLimiter::for('passkeys', function (Request $request) {
            $credentialId = $request->input('credential.id');

            return Limit::perMinute(10)->by(
                ($credentialId ?: $request->session()->getId()).'|'.$request->ip()
            );
        });
    }

    /**
     * Permite solo rutas internas y rechaza redirecciones externas.
     *
     * Evita que alguien arme una URL de login con redirect=https://sitio-falso
     * para mandar al usuario fuera de TRAMA después de autenticarse.
     */
    private function safeRedirectPath(mixed $value): ?string
    {
        if (! is_string($value) || $value === '' || ! str_starts_with($value, '/') || str_starts_with($value, '//')) {
            // null indica que no hay redirección segura y se usa la portada.
            return null;
        }

        // Ruta local segura, por ejemplo /noticias/titulo.
        return $value;
    }

    /**
     * Detecta enlaces de recuperacion vencidos o ya utilizados antes del envio.
     */
    private function resetLinkError(Request $request): ?string
    {
        $email = $request->query('email');
        $token = $request->route('token');

        if (! is_string($email) || $email === '' || ! is_string($token) || $token === '') {
            return __('passwords.token');
        }

        $user = User::query()
            ->where('email', $email)
            ->first();

        if (! $user) {
            return __('passwords.token');
        }

        return Password::broker(config('fortify.passwords'))->tokenExists($user, $token)
            ? null
            : __('passwords.token');
    }

}
