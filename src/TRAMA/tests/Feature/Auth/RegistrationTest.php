<?php

/* ============================================================================
 * TEST: RegistrationTest.php
 * ============================================================================
 *
 * Verifica el registro público de lectores.
 *
 * Comprueba que el formulario pueda abrirse, que una cuenta válida se cree con
 * rol reader, que se envíe la notificación de verificación y que el registro no
 * deje una sesión iniciada antes de confirmar el correo electrónico.
 * ============================================================================ */

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Notifications\TramaVerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_screen_can_be_rendered(): void
    {
        $response = $this->get('/register');

        $response->assertStatus(200);
    }

    public function test_new_users_can_register_and_are_sent_to_email_verification(): void
    {
        Notification::fake();

        $response = $this->post('/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password1',
            'password_confirmation' => 'password1',
        ]);

        $user = User::query()
            ->where('email', 'test@example.com')
            ->firstOrFail();

        $this->assertGuest();

        $response
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'verification-link-sent')
            ->assertRedirect(route('verification.notice', absolute: false));

        $this->assertSame('reader', $user->role);
        $this->assertTrue($user->is_active);
        $this->assertNull($user->email_verified_at);

        Notification::assertSentTo($user, TramaVerifyEmail::class);
    }

    public function test_registration_does_not_return_to_requested_page_before_email_verification(): void
    {
        Notification::fake();

        $response = $this->post('/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password1',
            'password_confirmation' => 'password1',
            'redirect' => '/noticias/congreso-debate-una-nueva-agenda-publica',
        ]);

        $user = User::query()
            ->where('email', 'test@example.com')
            ->firstOrFail();

        $this->assertGuest();

        $response
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'verification-link-sent')
            ->assertRedirect(route('verification.notice', absolute: false));

        Notification::assertSentTo($user, TramaVerifyEmail::class);
    }
}
