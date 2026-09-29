<?php

/* ============================================================================
 * NOTIFICATION: TramaResetPassword.php
 * ============================================================================
 *
 * Envía el correo para restablecer la contraseña de TRAMA.
 *
 * Construye el enlace con el token generado por Laravel y usa una vista HTML
 * propia para mantener el mismo diseño visual de los correos del portal.
 * ============================================================================ */

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TramaResetPassword extends Notification
{
    use Queueable;

    /**
     * Recibe el token generado por Laravel para esta solicitud de recuperación.
     */
    public function __construct(private readonly string $token)
    {
    }

    /**
     * Define que el restablecimiento se envía por correo.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Construye el mail con el enlace para crear una nueva contraseña.
     */
    public function toMail(object $notifiable): MailMessage
    {
        // El token identifica la solicitud de recuperación creada por Laravel.
        $resetUrl = route('password.reset', [
            // Token que Laravel validará cuando el usuario abra el formulario.
            'token' => $this->token,
            // Email incluido para precargar y validar la solicitud.
            'email' => $notifiable->getEmailForPasswordReset(),
        ]);

        // Usa vista propia para evitar el mail genérico del framework.
        return (new MailMessage)
            ->subject('Restablecer contraseña | TRAMA')
            ->view('emails.reset-password', [
                'resetUrl' => $resetUrl,
            ]);
    }
}
