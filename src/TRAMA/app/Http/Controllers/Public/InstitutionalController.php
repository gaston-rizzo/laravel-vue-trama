<?php

/* ============================================================================
 * CONTROLLER: InstitutionalController.php
 * ============================================================================
 *
 * Muestra las páginas públicas de TRAMA y recibe consultas.
 *
 * Las cuatro páginas comparten el mismo lenguaje visual del portal. Contacto
 * además permite enviar una consulta real al proyecto, incluida la opción
 * "Problemas con mi cuenta" utilizada desde el mensaje de cuenta bloqueada.
 * ============================================================================ */

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Http\Requests\PublicContactRequest;
use App\Mail\ContactMessageReceivedMail;
use App\Models\ContactMessage;
use App\Support\OperationFailureHandler;
use App\Support\TramaLog;
use App\Support\TramaBridge;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Inertia\Response;
use Throwable;

class InstitutionalController extends Controller
{
    public function __construct(
        private readonly OperationFailureHandler $operationFailures,
    ) {
    }

    /**
     * Explica qué es TRAMA y su enfoque editorial.
     */
    public function about(): Response
    {
        return TramaBridge::render('Public/About');
    }

    /**
     * Muestra información y formulario de contacto.
     */
    public function contact(Request $request): Response
    {
        return TramaBridge::render('Public/Contact', [
            'defaultName' => $request->user()?->name ?? '',
            'defaultEmail' => $request->user()?->email ?? '',
        ]);
    }

    /**
     * Guarda una consulta pública validada y avisa a la casilla interna de TRAMA.
     *
     * La persistencia es la fuente de verdad: si el SMTP falla, la consulta no se
     * pierde ni se obliga al usuario a reenviarla. El fallo de notificación queda
     * registrado para diagnóstico.
     */
    public function storeContact(PublicContactRequest $request): RedirectResponse
    {
        try {
            $contactMessage = ContactMessage::query()->create($request->validated());
        } catch (Throwable $exception) {
            $this->operationFailures->fail(
                $exception,
                'public_contact',
                'store_message',
                'No pudimos enviar tu consulta en este momento. Intentá nuevamente.',
                [
                    'email' => $request->validated('email'),
                    'reason' => $request->validated('reason'),
                ],
            );
        }

        try {
            Mail::to((string) config('mail.contact_address'))
                ->send(new ContactMessageReceivedMail($contactMessage));
        } catch (Throwable $exception) {
            TramaLog::error(TramaLog::mailFailureMessage($exception), [
                'controller' => self::class,
                'method' => 'storeContact',
                'contact_message_id' => $contactMessage->id,
                'recipient' => config('mail.contact_address'),
                'sender_email' => $contactMessage->email,
            ]);
        }

        return back()->with(
            'status',
            'Recibimos tu consulta. El equipo de TRAMA la revisará.'
        );
    }

    /**
     * Explica el tratamiento de datos y privacidad del portal.
     */
    public function privacy(): Response
    {
        return TramaBridge::render('Public/Privacy');
    }

    /**
     * Muestra las condiciones generales de uso del sitio.
     */
    public function terms(): Response
    {
        return TramaBridge::render('Public/Terms');
    }
}
