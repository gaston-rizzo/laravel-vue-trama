<?php

/* ============================================================================
 * MAIL: ContactMessageReceivedMail.php
 * ============================================================================
 *
 * Notifica a la casilla interna de TRAMA que llegó una nueva consulta pública.
 *
 * La consulta ya fue guardada en base de datos antes de crear este correo. El
 * Reply-To apunta al remitente para que el equipo pueda responderle desde su
 * cliente de correo sin copiar manualmente la dirección.
 * ============================================================================ */

namespace App\Mail;

use App\Models\ContactMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ContactMessageReceivedMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    /**
     * Recibe la consulta persistida que será incluida en la notificación.
     */
    public function __construct(public readonly ContactMessage $contactMessage)
    {
    }

    /**
     * Define asunto y Reply-To del mensaje interno.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            replyTo: [
                new Address(
                    $this->contactMessage->email,
                    $this->contactMessage->name,
                ),
            ],
            subject: sprintf(
                '[TRAMA · %s] Nueva consulta',
                $this->reasonSubjectLabel(),
            ),
        );
    }

    /**
     * Define la vista HTML y los datos visibles para el equipo de TRAMA.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.contact-message-received',
            with: [
                'reasonLabel' => $this->reasonLabel(),
            ],
        );
    }

    /**
     * Traduce el código interno del motivo a una etiqueta legible en el mail.
     */
    private function reasonLabel(): string
    {
        return match ($this->contactMessage->reason) {
            'account' => 'Problemas con mi cuenta',
            'editorial' => 'Editorial',
            'advertising' => 'Publicidad',            
            default => 'Otro',
        };
    }

    /**
     * Mantiene breve el asunto del correo sin perder el motivo completo dentro.
     */
    private function reasonSubjectLabel(): string
    {
        return $this->contactMessage->reason === 'account'
            ? 'Cuenta'
            : $this->reasonLabel();
    }
}
