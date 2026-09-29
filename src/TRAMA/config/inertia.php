<?php

/* ============================================================================
 * CONFIG: inertia.php
 * ============================================================================
 *
 * Configura Inertia para conectar Laravel con las pantallas Vue de TRAMA.
 *
 * Define si Laravel debe pedir renderizado SSR a Node, dónde está el archivo
 * compilado del servidor y dónde se buscan los componentes Vue que representan
 * cada pantalla del portal.
 * ============================================================================ */

$inertiaSsrBundle = env('INERTIA_SSR_BUNDLE', 'bootstrap/ssr/ssr.js');

// Normaliza la ruta del bundle SSR para que pueda configurarse como ruta
// relativa del proyecto o como ruta absoluta del sistema operativo.
// Ejemplo relativo: INERTIA_SSR_BUNDLE=bootstrap/ssr/ssr.js.
// Ejemplo absoluto: INERTIA_SSR_BUNDLE=C:\Users\PC\Desktop\project\TRAMA\bootstrap\ssr\ssr.js.
if (is_string($inertiaSsrBundle) && $inertiaSsrBundle !== '') {
    // Si el valor empieza con disco de Windows o barra inicial, ya es una ruta
    // absoluta. En ese caso se usa tal cual para no duplicar la carpeta base.
    $isAbsoluteInertiaSsrBundle = preg_match('/^(?:[A-Za-z]:[\\\\\/]|[\\\\\/])/', $inertiaSsrBundle) === 1;

    // Si el valor es relativo, se interpreta desde la raíz del proyecto. Así
    // "bootstrap/ssr/ssr.js" apunta al archivo generado por vite build --ssr.
    $inertiaSsrBundle = $isAbsoluteInertiaSsrBundle
        ? $inertiaSsrBundle
        : base_path($inertiaSsrBundle);
} else {
    // Valor por defecto usado por Inertia cuando Vite compila resources/js/ssr.js.
    $inertiaSsrBundle = base_path('bootstrap/ssr/ssr.js');
}

return [
    'ssr' => [
        // Interruptor maestro de SSR. HandleInertiaRequests lo restringe por
        // petición a la ruta de portada configurada en config/trama.php.
        'enabled' => (bool) env('INERTIA_SSR_ENABLED', true),

        // Dirección local del servidor SSR de Inertia. Laravel le envía la página
        // Inertia a esa URL y Node responde con el HTML ya renderizado.
        'url' => env('INERTIA_SSR_URL', 'http://127.0.0.1:13714'),

        // Obliga a comprobar que exista el bundle antes de intentar usar SSR.
        // Evita llamar a Node si todavía no se ejecutó vite build --ssr.
        'ensure_bundle_exists' => (bool) env('INERTIA_SSR_ENSURE_BUNDLE_EXISTS', true),

        // Archivo compilado que ejecuta Node. No es el fuente resources/js/ssr.js:
        // es el resultado generado dentro de bootstrap/ssr por Vite.
        'bundle' => $inertiaSsrBundle,
    ],

    // Cuando está en true, Inertia verifica que exista el componente Vue pedido
    // por Laravel. En TRAMA se deja activo para detectar nombres mal escritos.
    'ensure_pages_exist' => true,

    'page_paths' => [
        // Carpeta principal donde viven las pantallas Vue de TRAMA.
        resource_path('js/Pages'),
    ],

    'page_extensions' => [
        // Extensiones aceptadas al buscar componentes de página.
        'js',
        'jsx',
        'svelte',
        'ts',
        'tsx',
        'vue',
    ],

    // Mantiene el comportamiento normal de Inertia para insertar la página
    // inicial como JSON dentro del HTML.
    'use_script_element_for_initial_page' => (bool) env('INERTIA_USE_SCRIPT_ELEMENT_FOR_INITIAL_PAGE', false),

    'testing' => [
        // Configuración usada por pruebas de Inertia para localizar componentes.
        'ensure_pages_exist' => true,

        'page_paths' => [
            resource_path('js/Pages'),
        ],

        'page_extensions' => [
            'js',
            'jsx',
            'svelte',
            'ts',
            'tsx',
            'vue',
        ],
    ],

    'history' => [
        // Si se activa, Inertia cifra el estado que guarda en el historial del
        // navegador. TRAMA lo deja apagado porque no guarda datos sensibles ahí.
        'encrypt' => (bool) env('INERTIA_ENCRYPT_HISTORY', false),
    ],
];
