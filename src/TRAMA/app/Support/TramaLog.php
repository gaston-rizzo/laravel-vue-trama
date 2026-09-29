<?php

/* ============================================================================
 * SUPPORT: TramaLog.php
 * ============================================================================
 *
 * Helper central para escribir errores personalizados de TRAMA.
 *
 * Mantiene el log personalizado con un formato corto y legible, separado del
 * laravel.log normal.
 *
 * Ejemplo del formato final en el archivo:
 *
 * [2026-07-24 19:15:00] ERROR: SMTP Error: Could not authenticate.
 * {
 *     "controller": "ContactController",
 *     "method": "store",
 *     "email": "usuario@example.com"
 * }
 *
 * La fecha, la hora y el nivel ERROR los agrega el formatter del canal.
 * Esta clase agrega el mensaje principal y, si existe, el bloque JSON de contexto.
 * ============================================================================ */

namespace App\Support;

use Illuminate\Support\Facades\Log;
use Throwable;

final class TramaLog
{
    /**
     * Registra un error en el log personalizado de TRAMA.
     *
     * El mensaje queda en la primera línea.
     * El contexto opcional queda abajo como JSON, para que sea fácil de leer.
     */
    public static function error(string $message, array $context = []): void
    {
        // Usa solamente el canal propio de TRAMA; no escribe este formato en laravel.log.
        Log::channel('trama_unexpected')->error(
            // Une el mensaje con el JSON ya formateado.
            // Si no hay contexto, formatContext() devuelve texto vacío.
            $message.self::formatContext($context)
        );
    }

    /**
     * Convierte errores técnicos del mailer en mensajes cortos para el log.
     *
     * Evita guardar el texto enorme del mailer con todos los intentos SMTP.
     */
    public static function mailFailureMessage(Throwable $exception): string
    {
        // Se pasa a minúsculas para detectar el tipo de fallo sin depender del casing.
        $message = strtolower($exception->getMessage());

        // Fallo típico cuando el usuario o password SMTP son incorrectos.
        if (str_contains($message, 'authenticat')) {
            return 'SMTP Error: Could not authenticate.';
        }

        // Fallo típico cuando no se puede llegar al servidor SMTP.
        if (str_contains($message, 'connect')) {
            return 'SMTP Error: Could not connect to SMTP host.';
        }

        // Mensaje genérico si el error no encaja en los casos anteriores.
        return 'Email delivery failed.';
    }

    /**
     * Prepara el bloque JSON que acompaña al mensaje principal del error.
     *
     * Recibe datos como controller, method, email, status o url.
     * Devuelve una línea nueva + JSON bonito, o texto vacío si no hay datos útiles.
     */
    private static function formatContext(array $context): string
    {
        // Elimina claves con valor null para que el log no se llene de campos vacíos.
        $context = array_filter(
            $context,
            static fn (mixed $value): bool => $value !== null
        );

        // Si no quedó ningún dato útil, el log queda solamente con el mensaje.
        if ($context === []) {
            return '';
        }

        // JSON_PRETTY_PRINT deja cada dato en su propia línea.
        // JSON_UNESCAPED_UNICODE mantiene caracteres legibles.
        // JSON_UNESCAPED_SLASHES evita barras escapadas innecesarias en URLs o rutas.
        return "\n".json_encode(
            $context,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
    }
}
