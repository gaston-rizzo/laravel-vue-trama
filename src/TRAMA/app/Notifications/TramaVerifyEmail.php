<?php

/* ============================================================================
 * NOTIFICATION: TramaVerifyEmail.php
 * ============================================================================
 *
 * Envía el correo de verificación de cuenta de TRAMA.
 *
 * Genera el enlace que se envía por correo para confirmar la cuenta.
 *
 * Laravel agrega una firma de seguridad al enlace para comprobar que nadie haya
 * cambiado el ID del usuario ni el correo a verificar. También le pone vencimiento:
 * por defecto dura 60 minutos, salvo que se cambie el valor
 * auth.verification.expire en la configuración de Laravel. Si el usuario abre
 * el enlace después de ese tiempo, ya no sirve y debe pedir uno nuevo. Al usarlo,
 * la cuenta queda verificada, pero no se inicia sesión automáticamente.
 * ============================================================================ */

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;

class TramaVerifyEmail extends Notification
{
    use Queueable;

    /**
     * Define que la verificación se envía por correo.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Construye el mensaje que recibe el usuario al registrarse.
     */
    public function toMail(object $notifiable): MailMessage
    {
        // Cantidad de minutos durante los que el enlace de verificación será válido.
        // Laravel usa 60 minutos por defecto si no se definió otro valor en config/auth.php.
        $expirationMinutes = (int) config('auth.verification.expire', 60);

        /*
         * EXCEPCIÓN INTENCIONAL AL RELOJ EDITORIAL:
         *
         * El vencimiento de una URL firmada es una medida de seguridad, no una
         * fecha visible del contenido de TRAMA. Debe usar el reloj real para que
         * "60 minutos" signifique realmente 60 minutos aunque la demo esté pausada.
         */
        $verificationUrl = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes($expirationMinutes),
            [
                // Id del usuario que Laravel debe marcar como verificado.
                'id' => $notifiable->getKey(),
                // Hash del email para comprobar que el enlace corresponde a esa cuenta.
                'hash' => sha1($notifiable->getEmailForVerification()),
            ]
        );

        // El mail usa una vista HTML propia, sin el texto automático en inglés de Laravel.
        return (new MailMessage)
            ->subject('Verificación de cuenta | TRAMA')
            ->view('emails.verify-account', [
                'verificationUrl' => $verificationUrl,
            ]);
    }
}
