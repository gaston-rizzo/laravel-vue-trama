<?php

/* ============================================================================
 * SUPPORT: OperationFailureHandler.php
 * ============================================================================
 *
 * Convierte errores inesperados de operaciones recuperables en mensajes
 * seguros para el usuario.
 *
 * Puede utilizarse tanto en el sitio público como en los paneles internos de
 * TRAMA. El controlador indica mediante "area" en qué parte del sistema ocurrió
 * el problema y mediante "operation" qué acción concreta estaba ejecutándose.
 *
 * Por ejemplo:
 *
 *      area: public_comments
 *      operation: report_comment
 *
 * identifica un error ocurrido en el sitio público al reportar un comentario.
 *
 * Mientras que:
 *
 *      area: admin_articles
 *      operation: create_article
 *
 * identifica un error ocurrido en el panel interno al crear una noticia.
 *
 * El detalle técnico de la excepción queda registrado en el log personalizado
 * de TRAMA y nunca se muestra directamente al usuario.
 *
 * Las validaciones, errores de autenticación o permisos, recursos inexistentes
 * y caídas completas de la base de datos conservan el manejo normal de Laravel.
 *
 * Sin OperationFailureHandler, cada controlador tendría que repetir:
 *
 *     TramaLog::error(...);
 *
 *     throw ValidationException::withMessages([
 *         'operation' => 'Mensaje amigable...',
 *     ]);
 *
 * Con este manejador, cada controlador solamente necesita indicar:
 *
 *     - el área del sistema donde ocurrió el error;
 *     - la operación que estaba realizando;
 *     - el contexto adicional que debe quedar registrado;
 *     - el mensaje amigable que verá el usuario.
 *
 * ============================================================================ */

namespace App\Support;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;

use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

use Throwable;

final class OperationFailureHandler
{
    /**
     * Registra un error inesperado y devuelve un mensaje seguro al formulario.
     *
     * @param array<string, mixed> $context
     */
    public function fail(
        Throwable $exception,
        string $area,
        string $operation,
        string $userMessage,
        array $context = [],
    ): never {
        /*
         * Estas excepciones representan situaciones esperadas.
         *
         * No deben transformarse en un error general porque Laravel ya sabe
         * mostrar correctamente validaciones, permisos y recursos inexistentes.
         */
        if (
            $exception instanceof ValidationException
            || $exception instanceof AuthenticationException
            || $exception instanceof AuthorizationException
            || $exception instanceof ModelNotFoundException
            || $exception instanceof HttpExceptionInterface
            || $this->isDatabaseUnavailable($exception)
        ) {
            throw $exception;
        }

        /*
         * Registra exclusivamente el detalle técnico.
         *
         * El mensaje de la excepción, su archivo y su línea nunca se envían
         * al navegador.
         */
        TramaLog::error(
            'Falló una operación.',
            [
                ...$context,
                'area' => $area,
                'operation' => $operation,
                'exception_class' => $exception::class,
                'exception_message' => $exception->getMessage(),
                'exception_file' => $exception->getFile(),
                'exception_line' => $exception->getLine(),
            ]
        );

        /*
        * Devuelve el problema como un error de validación seguro.
        *
        * Inertia puede mostrarlo dentro de un formulario y una petición Axios lo
        * recibe como respuesta 422. En ambos casos se evita exponer el error técnico
        * original al usuario.
        */
        throw ValidationException::withMessages([
            'operation' => $userMessage,
        ]);
    }

    /**
     * Detecta una caída completa de la conexión con MySQL.
     *
     * Ese caso debe seguir llegando a bootstrap/app.php porque puede impedir
     * incluso leer la sesión, el usuario o las propiedades de Inertia.
     */
    private function isDatabaseUnavailable(Throwable $exception): bool
    {
        return $exception instanceof QueryException
            && str_contains(
                $exception->getMessage(),
                'SQLSTATE[HY000] [2002]'
            );
    }
}