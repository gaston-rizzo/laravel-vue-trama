<?php

/* ============================================================================
 * SUPPORT: TramaBridge.php
 * ============================================================================
 *
 * Actúa como página puente entre Laravel e Inertia para mostrar pantallas Vue. 
 * Conecta los controladores de Laravel con la página raíz de Inertia de TRAMA.
 *
 * Los controladores consultan la base de datos, aplican filtros, validaciones y
 * permisos, y preparan los datos necesarios para cada pantalla. Después llaman
 * a TramaBridge::render() y le entregan:
 *
 *      1. El nombre lógico de la pantalla Vue que debe mostrarse.
 *      2. Los datos que esa pantalla necesita.
 *
 * Ejemplo desde un controlador:
 *
 *      return TramaBridge::render('Admin/Articles/Index', [
 *          'articles' => $articles,
 *          'filters' => $filters,
 *      ]);
 *
 * En este ejemplo, el controlador le pasa a TramaBridge:
 *
 * - 'Admin/Articles/Index':
 *      nombre de la pantalla Vue que debe abrirse.
 *
 * - $articles:
 *      listado de noticias preparado por el controlador.
 *
 * - $filters:
 *      filtros aplicados o disponibles para esa pantalla.
 *
 * TramaBridge no obtiene esos datos por su cuenta. Su responsabilidad es
 * empaquetarlos en una respuesta de Inertia y enviarlos siempre a la misma
 * página raíz:
 *
 *      resources/js/Pages/Trama.vue
 *
 * Trama.vue recibe:
 *
 * - screen:
 *      nombre de la pantalla Vue interna que debe mostrarse.
 *      Ejemplos: Public/Home, Public/ArticleShow, Admin/Articles/Form.
 *
 * - screenProps:
 *      datos que el controlador entregó a TramaBridge.
 *      Ejemplos: noticias, comentarios, categorías, filtros o permisos.
 *
 * El recorrido completo es:
 *
 *      Controlador de Laravel
 *          → prepara los datos
 *          → llama a TramaBridge::render()
 *          → TramaBridge crea la respuesta Inertia
 *          → Trama.vue selecciona la pantalla Vue
 *          → Vue muestra la interface correspondiente
 *
 * De esta forma Laravel conserva las rutas, consultas, validaciones, permisos
 * y redirecciones, mientras Vue se encarga de representar la interface y de
 * mantener la navegación Inertia sin recargar por completo el documento.
 * ============================================================================ */

namespace App\Support;

use Inertia\Inertia;
use Inertia\Response;

class TramaBridge
{
    /**
     * Devuelve la página raíz Inertia de TRAMA indicando qué pantalla Vue mostrar.
     *
     * Esta función es usada por los controladores para no repetir en cada método
     * el mismo Inertia::render('Trama', ...). El controlador solamente decide la
     * pantalla interna y arma sus datos; este puente los empaqueta con un formato
     * único para que Trama.vue pueda resolver el componente correcto.
     *
     * $screen es el nombre lógico de la pantalla Vue interna.
     * $props son los datos propios de esa pantalla, ya consultados y preparados
     * por Laravel antes de llegar al frontend.
     *
     * @param  array<string, mixed>  $props
     */
    public static function render(string $screen, array $props = []): Response
    {
        /*
         * Segunda barrera del alcance SSR: aun dentro de la ruta home, solo la
         * pantalla lógica Public/Home puede pre-renderizarse. Esto evita que una
         * pantalla de error u otra respuesta accidental use Node por heredar la
         * ruta de la portada.
         */
        $ssrScreen = (string) config('trama.ssr.screen', 'Public/Home');

        config([
            'inertia.ssr.enabled' => (bool) config('inertia.ssr.enabled')
                && $screen === $ssrScreen,
        ]);

        // Inertia renderiza siempre la misma página raíz: Trama.vue.
        // Esa página funciona como entrada única de la interface Vue del portal.
        return Inertia::render('Trama', [
            // screen le dice a Trama.vue qué componente interno debe mostrar.
            // No es una URL ni una ruta de Vue Router; es una clave acordada entre
            // Laravel y el mapa de pantallas definido en resources/js/Pages/Trama.vue.
            'screen' => $screen,
            // screenProps transporta los datos que necesita esa pantalla puntual.
            // Laravel puede devolver errores, formularios, noticias, comentarios,
            // filtros, permisos o cualquier estructura ya lista para renderizar.
            'screenProps' => $props,
        ]);
    }
}
