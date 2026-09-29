<?php

/* ============================================================================
 * CONFIG: app.php
 * ============================================================================
 *
 * Configuración general de la aplicación TRAMA.
 *
 * Define nombre, entorno, zona horaria, idioma, cifrado y proveedores básicos del framework.
 * ============================================================================ */

return [

    /*
    |--------------------------------------------------------------------------
    | Nombre de la aplicación
    |--------------------------------------------------------------------------
    |
    | Este valor identifica a TRAMA en notificaciones, correos y cualquier
    | interface donde Laravel necesite mostrar el nombre del sistema.
    |
    */

    'name' => env('APP_NAME', 'TRAMA'),

    /*
    |--------------------------------------------------------------------------
    | Entorno de ejecución
    |--------------------------------------------------------------------------
    |
    | Indica si la aplicación se ejecuta en desarrollo, pruebas o producción.
    | El valor se configura mediante APP_ENV en el archivo .env.
    |
    */

    'env' => env('APP_ENV', 'production'),

    /*
    |--------------------------------------------------------------------------
    | Modo de depuración
    |--------------------------------------------------------------------------
    |
    | Cuando está habilitado, Laravel muestra errores detallados y trazas.
    | Debe permanecer desactivado en producción para no exponer información.
    |
    */

    'debug' => (bool) env('APP_DEBUG', false),

    /*
    |--------------------------------------------------------------------------
    | URL principal
    |--------------------------------------------------------------------------
    |
    | Laravel utiliza esta dirección para generar enlaces absolutos desde
    | comandos, notificaciones y procesos que no reciben una petición web.
    |
    */

    'url' => env('APP_URL', 'http://localhost'),

    /*
    |--------------------------------------------------------------------------
    | Zona horaria
    |--------------------------------------------------------------------------
    |
    | Define la zona utilizada para publicaciones, programación editorial y
    | fechas visibles. Por defecto se utiliza la hora de Buenos Aires.
    |
    */

    'timezone' => env('APP_TIMEZONE', 'America/Argentina/Buenos_Aires'),

    /*
    |--------------------------------------------------------------------------
    | Idioma y datos regionales
    |--------------------------------------------------------------------------
    |
    | Define el idioma principal, el idioma de respaldo y la configuración
    | regional utilizada por Faker para generar datos de ejemplo.
    |
    */

    'locale' => env('APP_LOCALE', 'es'),

    'fallback_locale' => env('APP_FALLBACK_LOCALE', 'es'),

    'faker_locale' => env('APP_FAKER_LOCALE', 'es_AR'),

    /*
    |--------------------------------------------------------------------------
    | Encryption Key
    |--------------------------------------------------------------------------
    |
    | This key is utilized by Laravel's encryption services and should be set
    | to a random, 32 character string to ensure that all encrypted values
    | are secure. You should do this prior to deploying the application.
    |
    */

    'cipher' => 'AES-256-CBC',

    'key' => env('APP_KEY'),

    'previous_keys' => [
        ...array_filter(
            explode(',', (string) env('APP_PREVIOUS_KEYS', ''))
        ),
    ],

    /*
    |--------------------------------------------------------------------------
    | Maintenance Mode Driver
    |--------------------------------------------------------------------------
    |
    | These configuration options determine the driver used to determine and
    | manage Laravel's "maintenance mode" status. The "cache" driver will
    | allow maintenance mode to be controlled across multiple machines.
    |
    | Supported drivers: "file", "cache"
    |
    */

    'maintenance' => [
        'driver' => env('APP_MAINTENANCE_DRIVER', 'file'),
        'store' => env('APP_MAINTENANCE_STORE', 'database'),
    ],

];
