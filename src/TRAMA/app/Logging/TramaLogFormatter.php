<?php

/* ============================================================================
 * LOGGING: TramaLogFormatter.php
 * ============================================================================
 *
 * Define el formato del archivo de log personalizado de TRAMA.
 *
 * Laravel crea el canal de log y luego llama a esta clase con el logger ya
 * preparado. Esta clase no decide qué errores se guardan: solo cambia cómo se
 * escribe cada línea para que el archivo quede fácil de leer.
 *
 * Ejemplo del formato final en el archivo:
 *
 *      [2026-07-24 18:49:02] ERROR: SMTP Error: Could not authenticate.
 *      {
 *          "controller": "ContactController",
 *          "method": "store",
 *          "email": "usuario@example.com"
 *      }
 * ============================================================================ */

namespace App\Logging;

use Illuminate\Log\Logger as LaravelLogger;

use Monolog\Handler\FormattableHandlerInterface;
use Monolog\Formatter\LineFormatter;
use Monolog\Logger as MonologLogger;

class TramaLogFormatter
{
    /**
     * Aplica el formato a todos los manejadores internos del canal
     * que admiten un formateador personalizado.
     */
    public function __invoke(LaravelLogger $logger): void
    {
        /** @var MonologLogger $monolog */
        $monolog = $logger->getLogger();

        /*
        * Si Laravel cambiara el logger interno, se evita llamar métodos
        * pertenecientes específicamente a Monolog.
        */
        if (! $monolog instanceof MonologLogger) {
            return;
        }

        $formatter = new LineFormatter(
            "[%datetime%] %level_name%: %message%\n",
            "Y-m-d H:i:s",
            // Permite saltos de línea reales para que el JSON sea legible.
            true,
            // Evita una línea vacía adicional al final de cada registro.
            true
        );

        foreach ($monolog->getHandlers() as $handler) {
            /*
            * HandlerInterface representa cualquier manejador de Monolog,
            * pero no todos permiten asignar un formateador.
            *
            * FormattableHandlerInterface garantiza que el manejador posee
            * el método setFormatter().
            */
            if (! $handler instanceof FormattableHandlerInterface) {
                continue;
            }

            $handler->setFormatter($formatter);
        }
    }
}
