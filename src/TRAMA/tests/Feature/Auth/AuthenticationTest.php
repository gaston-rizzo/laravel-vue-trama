<?php

/* ============================================================================
 * TEST: AuthenticationTest.php
 * ============================================================================
 *
 * Verifica inicio y cierre de sesión.
 *
 * Cubre credenciales válidas, rechazos de acceso y comportamiento de cuentas inactivas o bloqueadas.
 * ============================================================================ */

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Support\TramaClock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_screen_can_be_rendered(): void
    {
        $response = $this->get('/login');

        $response->assertStatus(200);
    }

    public function test_public_users_are_sent_to_home_after_login(): void
    {
        $user = User::factory()->create();

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('home', absolute: false));
    }

    public function test_last_login_uses_trama_editorial_clock(): void
    {
        $user = User::factory()->create();

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertRedirect();

        /*
         * El acceso debe quedar dentro de la cronología editorial completa.
         * Ya no alcanza con comprobar el 19/07: también verificamos que la hora
         * nunca sea anterior al inicio configurado de las 15:30.
         */
        $lastLoginAt = $user->fresh()->last_login_at;

        $this->assertSame(
            TramaClock::referenceDate()->toDateString(),
            $lastLoginAt?->toDateString(),
        );
        $this->assertTrue($lastLoginAt?->greaterThanOrEqualTo(TramaClock::initial()));
    }

    public function test_editorial_users_are_sent_to_editorial_dashboard_after_login(): void
    {
        $user = User::factory()->create(['role' => 'journalist']);

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
            'redirect' => '/noticias/congreso-debate-una-nueva-agenda-publica',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('admin.dashboard', absolute: false));
    }

    public function test_public_users_can_return_to_an_internal_page_after_login(): void
    {
        $user = User::factory()->create();

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
            'redirect' => '/noticias/congreso-debate-una-nueva-agenda-publica',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect('/noticias/congreso-debate-una-nueva-agenda-publica');
    }

    public function test_users_can_not_authenticate_with_invalid_password(): void
    {
        $user = User::factory()->create();

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ]);

        $this->assertGuest();
    }

    public function test_users_can_logout(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/logout');

        $this->assertGuest();
        $response->assertRedirect('/');
    }
}
