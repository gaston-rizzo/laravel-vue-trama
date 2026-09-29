/* ============================================================================
 * FRONTEND: app.js
 * ============================================================================
 *
 * Entrada del navegador para Vue/Inertia.
 *
 * TRAMA reserva SSR para la portada. Cuando Laravel entrega ese HTML inicial,
 * Vue lo hidrata mediante createSSRApp. En el resto de las rutas el contenedor
 * llega sin pre-render y Vue realiza un montaje normal mediante createApp.
 * ============================================================================ */

import './bootstrap';

import { createInertiaApp } from '@inertiajs/vue3';
import { createApp, createSSRApp, h } from 'vue';
import { ZiggyVue } from 'ziggy-js';
import { resolveInertiaPage } from './inertia-pages';

const appName = import.meta.env.VITE_APP_NAME || 'TRAMA';

createInertiaApp({
    /*
     * Agrega el nombre del portal después del título de cada página.
     *
     * Ejemplo:
     *
     * Noticias - TRAMA
     */
    title: (title) => `${title} - ${appName}`,

    /*
     * Resuelve dinámicamente el componente correspondiente a cada página
     * enviada por Laravel mediante Inertia.
     */
    resolve: resolveInertiaPage,

    /*
    * Crea la aplicación de Vue dentro del contenedor generado por Inertia.
    *
    * Si el contenedor ya contiene HTML, la portada fue renderizada mediante SSR
    * y debe hidratarse con createSSRApp.
    *
    * Si el contenedor está vacío, la ruta usa Inertia/Vue normal y se monta con
    * createApp.
    *
    * Parámetros entregados automáticamente por createInertiaApp:
    *
    * el: Elemento HTML donde se monta la aplicación de Vue.    
    * App: Componente raíz de Inertia.    
    * props: Propiedades iniciales enviadas al componente raíz.
    * plugin: Plugin de Inertia que se registra mediante .use(plugin).
    */
    setup({ el, App, props, plugin }) {
        const createVueApp = el.innerHTML.trim()
            ? createSSRApp
            : createApp;

        return createVueApp({
            render: () => h(App, props),
        })
            .use(plugin)
            .use(ZiggyVue)
            .mount(el);
    },

    /*
     * Configuración de la barra superior mostrada durante las navegaciones
     * realizadas mediante Inertia.
     */
    progress: {
        color: '#D6A23A',
    },
});