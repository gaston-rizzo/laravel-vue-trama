<?php

/* ============================================================================
 * CONFIG: trama.php
 * ============================================================================
 *
 * Configuración propia de TRAMA.
 *
 * Contiene rutas y valores del portal que no pertenecen a la configuración
 * base de Laravel. Así el código puede leer valores claros desde config()
 * mientras el entorno define las carpetas reales desde el archivo .env.
 * ============================================================================ */

// env() obtiene el valor de TRAMA_BANNERS_PATH desde las variables de entorno,
// normalmente definidas en el archivo .env.
// path donde se guardan los banners subidos 
$tramaBannersPath = env('TRAMA_BANNERS_PATH');

if (is_string($tramaBannersPath) && $tramaBannersPath !== '') {
    // La variable puede contener una ruta absoluta de Windows o Linux, o una
    // ruta relativa a la raíz del proyecto, como public/images/banners.
    $isAbsoluteTramaBannersPath =
        preg_match('/^(?:[A-Za-z]:[\\\\\/]|[\\\\\/])/', $tramaBannersPath) === 1;

    $tramaBannersPath = $isAbsoluteTramaBannersPath
        ? $tramaBannersPath
        : base_path($tramaBannersPath);
} else {
    // Mantiene una ubicación pública conocida cuando el entorno no la define.
    $tramaBannersPath = public_path('images/banners');
}

// path donde se guardan las portadas procesadas de las noticias.
$tramaArticleCoversPath = env('TRAMA_ARTICLE_COVERS_PATH');

if (
    is_string($tramaArticleCoversPath)
    && $tramaArticleCoversPath !== ''
) {
    // La ruta de portadas admite la misma configuración flexible que banners:
    // puede ser absoluta o relativa a la raíz del proyecto.
    $isAbsoluteTramaArticleCoversPath =
        preg_match(
            '/^(?:[A-Za-z]:[\\\\\/]|[\\\\\/])/',
            $tramaArticleCoversPath
        ) === 1;

    $tramaArticleCoversPath = $isAbsoluteTramaArticleCoversPath
        ? $tramaArticleCoversPath
        : base_path($tramaArticleCoversPath);
} else {
    // Si no se define la variable, las portadas se guardan dentro de public/.
    $tramaArticleCoversPath = public_path('images/articles');
}

return [
    /*
     * Política SSR propia de TRAMA.
     *
     * El interruptor maestro sigue siendo INERTIA_SSR_ENABLED, pero el portal
     * solo pre-renderiza la portada. El nombre de ruta se mantiene aquí para
     * que HandleInertiaRequests no dependa de una cadena repartida por el código.
     */
    'ssr' => [
        'enabled' => (bool) env('INERTIA_SSR_ENABLED', true),
        'route' => 'home',
        'screen' => 'Public/Home',
    ],

    /*
    * Fecha que TRAMA toma como “hoy” dentro del portal.
    *
    * Como TRAMA es un proyecto de demostración sus noticias,
    * eventos y datos de prueba están preparados alrededor de una fecha concreta.
    * Mantener una fecha de referencia evita que ese contenido pierda coherencia
    * a medida que avanza la fecha real del sistema.
    *
    * Puede modificarse mediante TRAMA_REFERENCE_DATE en el archivo .env. Cuando
    * la variable no existe, TRAMA toma 2026-07-19 como fecha actual del portal.
    *
    * En una instalación de producción con contenido real, esta referencia debe
    * reemplazarse por la fecha actual obtenida mediante now().
    */
    'reference_date' => env(
        'TRAMA_REFERENCE_DATE',
        '2026-07-19'
    ),

    /*
     * Hora mínima desde la que comienza la primera sesión de demostración.
     *
     * No se asigna esta hora a todas las operaciones. Es solamente el punto de
     * partida inicial. Después TramaClock avanza con el tiempo real transcurrido
     * y conserva el último instante entre sesiones para no retroceder.
     *
     * Ejemplo:
     *     TRAMA_REFERENCE_DATE=2026-07-19
     *     TRAMA_REFERENCE_TIME=15:30:00
     *
     *     Primera actividad -> 2026-07-19 15:30:00
     *     Cinco minutos después -> 2026-07-19 15:35:00
     */
    'reference_time' => env(
        'TRAMA_REFERENCE_TIME',
        '15:30:00'
    ),

    /*
     * Después de este período sin peticiones web, el reloj se considera pausado.
     * La próxima sesión continúa desde el último instante editorial y no suma
     * las horas durante las que el proyecto estuvo cerrado o sin uso.
     */
    'clock_session_timeout_minutes' => 30,

    'advertisements' => [
        // Carpeta física donde Laravel guarda los archivos de banners.
        'banner_path' => rtrim(
            str_replace(
                ['\\', '/'],
                DIRECTORY_SEPARATOR,
                $tramaBannersPath
            ),
            DIRECTORY_SEPARATOR
        ),

        // URL pública correspondiente a public/images/banners.
        'banner_url' => '/images/banners',
    ],

    'articles' => [
        /*
        * Carpeta física donde se almacenan las portadas de las noticias.
        *
        * str_replace() normaliza los separadores de la ruta según el sistema
        * operativo. Así se evita obtener una ruta mezclada como:
        *
        * C:\proyecto\TRAMA\public/images/articles/
        *
        * En Windows quedará:
        *
        * C:\proyecto\TRAMA\public\images\articles
        *
        * En Linux quedará:
        *
        * /var/www/TRAMA/public/images/articles
        *
        * rtrim() elimina cualquier separador sobrante al final de la ruta.
        */
        'cover_path' => rtrim(
            str_replace(
                ['\\', '/'],
                DIRECTORY_SEPARATOR,
                $tramaArticleCoversPath
            ),
            DIRECTORY_SEPARATOR
        ),

        // URL pública utilizada para mostrar las portadas desde el navegador.
        'cover_url' => '/images/articles',

        /*
         * Porcentajes mínimos a partir de los cuales una portada se considera
         * peligrosa y se rechaza automáticamente.
         */
        'image_safety' => [
            /*
            * Una portada se rechaza automáticamente cuando la probabilidad de
            * contenido sexual alcanza o supera el 80 %.
            */
            'nsfw_block_threshold' => 0.80,

            /*
            * Una portada se rechaza automáticamente cuando la probabilidad de
            * contenido gráfico extremo o gore alcanza o supera el 80 %.
            */
            'nsfl_block_threshold' => 0.80,
        ],
    ],

    /*
    * Ruta absoluta del ejecutable de Node.js utilizado por los procesos
    * internos de TRAMA.
    * Cada entorno debe configurar su propia ruta absoluta.
    */
    'node_binary' => env('TRAMA_NODE_BINARY'),
];