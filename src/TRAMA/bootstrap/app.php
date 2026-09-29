<?php

/* ============================================================================
 * BOOTSTRAP: app.php
 * ============================================================================
 *
 * Configura el arranque principal de Laravel para TRAMA.
 *
 * Este archivo no define pantallas del sitio. Define cómo se levanta la
 * aplicación antes de ejecutar cualquier ruta:
 *
 *      - Qué archivos contienen rutas web y comandos de consola.
 *      - Qué middleware se agrega al grupo web de Laravel.
 *      - Qué nombres cortos existen para middleware propios del proyecto.
 *      - Cómo se registran errores críticos en el log personalizado de TRAMA.
 *      - Qué respuesta se muestra cuando la base de datos no está disponible.
 *
 * La vista errors.database no aparece en routes/web.php porque no es una página
 * navegable. Es una respuesta de emergencia que Laravel devuelve cuando una
 * excepción de conexión impide cargar la página normal.
 * ============================================================================ */

use App\Support\TramaLog;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    // Archivos principales que Laravel debe registrar al iniciar la aplicación.
    ->withRouting(
        // Rutas HTTP del portal público, autenticación y panel editorial.
        web: __DIR__.'/../routes/web.php',
        // Comandos de consola y tareas programadas del scheduler.
        commands: __DIR__.'/../routes/console.php',
        // Ruta simple usada para comprobar que Laravel responde correctamente.
        health: '/up',
    )
    // Configura el grupo de middleware de la aplicación.
    // Un middleware intercepta las peticiones HTTP antes o después de que
    // lleguen al controlador para aplicar lógica común, como autenticación,
    // sesiones, permisos o el registro de actividad del reloj editorial.
    ->withMiddleware(function (Middleware $middleware): void {
        // Agrega middleware al grupo web estándar de Laravel.
        // Todas las rutas de routes/web.php pasan por este grupo.
        $middleware->web(append: [
            // Activa o reanuda el reloj editorial antes de que cualquier pantalla
            // consulte fechas, categorías públicas o timestamps del usuario.
            \App\Http\Middleware\AdvanceTramaClock::class,
            // Expulsa en la siguiente petición cualquier sesión perteneciente a una cuenta bloqueada.
            \App\Http\Middleware\EnsureActiveAccount::class,
            // Unifica en backend los máximos de email y contraseña de los formularios de Fortify.
            \App\Http\Middleware\ValidateAuthInputLimits::class,
            // Comparte props globales con Inertia, como usuario, flash, categorías y banners.
            \App\Http\Middleware\HandleInertiaRequests::class,
            // Agrega headers para assets precargados por Vite/Inertia cuando corresponde.
            \Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets::class,
        ]);

        // Alias propios de TRAMA para middleware.
        //
        // Un alias permite escribir un nombre corto en una ruta en lugar del
        // nombre completo de la clase. Por ejemplo, si una ruta tuviera:
        //
        // Route::middleware('admin')->group(...);
        //
        // Laravel sabría que "admin" significa:
        // App\Http\Middleware\EnsureAdminRole::class
        //
        // En routes/web.php se usan clases explícitas para que el IDE marque
        // menos advertencias, pero los alias se mantienen registrados porque son
        // parte normal de la configuración de Laravel y pueden servir en rutas
        // futuras o en paquetes que esperen nombres de middleware.
        $middleware->alias([
            // Valida que el usuario tenga rol interno activo para entrar al panel editorial.
            'editorial' => \App\Http\Middleware\EnsureEditorialAccess::class,
            // Valida que el usuario tenga rol administrador para módulos sensibles.
            'admin' => \App\Http\Middleware\EnsureAdminRole::class,
            // Permite acceder al módulo de noticias
            // únicamente a periodistas y editores activos.
            'articles' => \App\Http\Middleware\EnsureArticleAccess::class,
            // Permite acceder a la moderación de comentarios
            // únicamente a editores activos.
            'comment-moderation' => \App\Http\Middleware\EnsureCommentModerationAccess::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Define qué excepciones deben escribirse en el log personalizado.
        // Este reporte corre antes de renderizar la respuesta para el navegador.
        $exceptions->report(function (Throwable $exception): bool|null {
            // Caso conocido: MySQL no está disponible.
            // QueryException indica un error al consultar la base.
            // SQLSTATE[HY000] [2002] identifica fallo de conexión con MySQL.
            // Se registra como 503 porque la aplicación está viva, pero un
            // servicio necesario para responder está caído.
            $isDatabaseUnavailable = $exception instanceof QueryException
                && str_contains($exception->getMessage(), 'SQLSTATE[HY000] [2002]');

            // Error 500 real o inesperado.
            // Si no es una excepción HTTP conocida, Laravel la trata como error interno.
            // Si es HTTP, solo se registra cuando el status es exactamente 500.
            // No se mezclan 404, 403 o 419 porque son respuestas esperadas del sitio.
            $isInternalServerError = (! $exception instanceof HttpExceptionInterface)
                || $exception->getStatusCode() === 500;

            // No se registran validaciones ni errores esperados como 404, 403, 419 o 422.
            // Este canal queda reservado para SMTP, 500 y 503 de servicio caído.
            if ($exception instanceof ValidationException
                || $exception instanceof AuthenticationException
                || $exception instanceof AuthorizationException
                || $exception instanceof ModelNotFoundException
                || $exception instanceof TokenMismatchException
                || (! $isDatabaseUnavailable && ! $isInternalServerError)) {
                // null le dice a Laravel que siga con su manejo normal de reporte.
                // En la práctica, esta rama evita llenar el log propio con errores esperados.
                return null;
            }

            // Obtiene la request actual para sumar contexto útil al log.
            // Si el error ocurre en consola, request() existe pero puede no tener ruta HTTP.
            $request = request();
            // La ruta permite saber qué endpoint se estaba intentando ejecutar.
            $route = $request->route();
            // getActionName devuelve Controller@method, Closure o texto interno de Laravel.
            $routeAction = optional($route)->getActionName();
            // Valores por defecto cuando el error ocurre antes de resolver una ruta concreta.
            $controller = 'Application';
            $method = 'report';

            if (is_string($routeAction) && str_contains($routeAction, '@')) {
                // Caso normal: una acción tipo App\Http\Controllers\XController@method.
                [$controller, $method] = explode('@', $routeAction, 2);
                // Guarda solo el nombre corto del controller para que el log sea legible.
                $controller = class_basename($controller);
            } elseif (app()->runningInConsole()) {
                // Caso consola: artisan, scheduler, seeders o comandos manuales.
                $controller = 'Console';
                $method = 'artisan';
            } elseif ($route !== null) {
                // Caso rutas anónimas: closures definidas directamente en routes/web.php.
                $controller = 'RouteClosure';
                $method = $request->method().' '.$route->uri();
            }

            // Si la ruta tiene un parámetro article, se intenta registrar su id.
            // Puede llegar como modelo Article por route model binding o como valor simple.
            $routeArticle = $request->route('article');
            $articleId = is_object($routeArticle) && method_exists($routeArticle, 'getKey')
                ? $routeArticle->getKey()
                : $routeArticle;

            // Para base caída se evita guardar el SQL completo en la primera línea.
            // El log conserva el status 503 y la ruta, pero no muestra una consulta gigante.
            $logMessage = $isDatabaseUnavailable
                ? 'Database Error: Connection failed.'
                : $exception->getMessage();

            // se registra el error en el log
            TramaLog::error($logMessage, [
                // Controller o contexto principal donde ocurrió el error.
                'controller' => $controller,
                // Método, comando o ruta closure que estaba ejecutándose.
                'method' => $method,
                // 503 para base caída, 500 para error interno inesperado.
                'status' => $isDatabaseUnavailable ? 503 : 500,
                // Usuario autenticado, si se pudo leer sesión antes del fallo.
                'user_id' => optional($request->user())->id,
                // Email enviado en formularios como registro o login si existe.
                'email' => $request->input('email'),
                // Slug de noticia o búsqueda si estaba presente en ruta o formulario.
                'slug' => $request->route('slug') ?? $request->input('slug'),
                // Id de noticia relacionado con la ruta o formulario, si existe.
                'article_id' => $articleId ?? $request->input('article_id'),
            ]);

            // false evita duplicar este reporte en laravel.log.
            return false;
        });

        // Define qué debe ver el usuario cuando la base de datos no responde.
        // Esto no es una ruta web: Laravel lo ejecuta solo cuando se lanza una
        // QueryException de conexión durante cualquier request.
        $exceptions->render(function (QueryException $exception, Request $request) {
            // Si el QueryException no es caída de conexión MySQL, Laravel debe seguir
            // con su render normal para no ocultar otro tipo de error.
            if (! str_contains($exception->getMessage(), 'SQLSTATE[HY000] [2002]')) {
                return null;
            }

            /*
            * Las peticiones iniciadas por Inertia necesitan abandonar la navegación
            * interna de Vue antes de mostrar la página de emergencia.
            *
            * Si se devuelve directamente una vista Blade a una petición Inertia,
            * Inertia interpreta el HTML como una respuesta externa y puede mostrarlo
            * dentro de su modal de diagnóstico, dejando el panel visible detrás.
            *
            * Inertia::location() fuerza una navegación completa del navegador hacia
            * la página desde la que se inició la operación. Si MySQL continúa caído,
            * esa nueva petición normal volverá a entrar en este handler y mostrará
            * errors.database ocupando toda la ventana.
            */
            if ($request->header('X-Inertia')) {
                $location = $request->headers->get('Referer') ?: url('/');

                return \Inertia\Inertia::location($location);
            }

            // Las llamadas fetch, Axios o endpoints que esperan JSON
            // reciben una respuesta estructurada sin HTML.
            if ($request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'code' => 'DATABASE_UNAVAILABLE',
                    'message' => 'La página no está disponible en este momento.',
                ], 503);
            }

            // Una navegación normal del navegador recibe directamente la vista Blade
            // de emergencia, independiente de Vue, Inertia y el panel editorial.
            if (view()->exists('errors.database')) {
                // resources/views/errors/database.blade.php
                // Status 503 indica "servicio temporalmente no disponible".
                return response()->view('errors.database', [], 503);
            }

            // Respaldo mínimo si por alguna razón la vista de emergencia no existe.
            return response('La página no está disponible en este momento.', 503);
        });

        // Personaliza errores HTTP esperados sin exponer detalles técnicos al visitante.
        // La caída de MySQL mantiene la vista Blade independiente definida arriba.
        $exceptions->render(function (HttpExceptionInterface $exception, Request $request) {
            $status = $exception->getStatusCode();

            if (! in_array($status, [403, 404, 419, 503], true)) {
                return null;
            }

            if ($request->expectsJson()) {
                return response()->json([
                    'message' => match ($status) {
                        403 => 'No tenés acceso a esta sección.',
                        404 => 'La página solicitada no existe o fue retirada.',
                        419 => 'Tu sesión venció. Actualizá la página e intentá nuevamente.',
                        503 => 'TRAMA no está disponible temporalmente. Intentá nuevamente en unos minutos.',
                    },
                ], $status);
            }

            if ($status === 419 && $request->isMethodSafe() === false) {
                return redirect()->back()->with(
                    'status',
                    'Tu sesión venció. Actualizá la página e intentá nuevamente.'
                );
            }

            return \App\Support\TramaBridge::render('Error', [
                'statusCode' => $status,
            ])->toResponse($request)->setStatusCode($status);
        });

        // Los errores internos no controlados muestran una pantalla institucional.
        // El detalle ya fue enviado al log por el reporter anterior.
        $exceptions->render(function (Throwable $exception, Request $request) {
            if ($exception instanceof QueryException
                || $exception instanceof ValidationException
                || $exception instanceof AuthenticationException
                || $exception instanceof AuthorizationException
                || $exception instanceof ModelNotFoundException
                || $exception instanceof TokenMismatchException
                || $exception instanceof HttpExceptionInterface) {
                return null;
            }

            if ($request->expectsJson()) {
                return response()->json([
                    'message' => 'Ocurrió un problema inesperado. Intentá nuevamente.',
                ], 500);
            }

            return \App\Support\TramaBridge::render('Error', [
                'statusCode' => 500,
            ])->toResponse($request)->setStatusCode(500);
        });
    })->create();
