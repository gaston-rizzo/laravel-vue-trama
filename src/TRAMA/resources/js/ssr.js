/* ============================================================================
 * FRONTEND: ssr.js
 * ============================================================================
 *
 * Entrada del servidor para Vue/Inertia.
 *
 * Este archivo lo ejecuta Node únicamente para la portada de TRAMA. Laravel
 * desactiva SSR por petición para todas las demás rutas. Usa createSSRApp porque
 * en este punto no existe DOM real: Vue genera el HTML inicial de Home y el
 * navegador lo hidrata después.
 * ============================================================================ */

import './bootstrap';

import { createInertiaApp } from '@inertiajs/vue3';
import createServer from '@inertiajs/vue3/server';
import { renderToString } from '@vue/server-renderer';
import { createSSRApp, h } from 'vue';
import { ZiggyVue } from 'ziggy-js';
import { resolveInertiaPage } from './inertia-pages';

// Nombre visible de la aplicación. Vite lo toma del entorno si existe; si no,
// se usa TRAMA para construir títulos como "Portada - TRAMA".
const appName = import.meta.env.VITE_APP_NAME || 'TRAMA';

// createServer levanta el renderizador SSR de Inertia. Por política de TRAMA,
// Laravel solo le envía la página de portada y Node devuelve su HTML inicial.
createServer((page) => createInertiaApp({
    // Página Inertia recibida desde Laravel: incluye el componente a renderizar,
    // la URL actual y las props que necesita la pantalla.
    page,
    // Convierte la aplicación Vue en un string HTML que Laravel puede insertar
    // dentro de resources/views/app.blade.php antes de responder al navegador.
    render: renderToString,
    // Arma el título final de cada pantalla respetando el nombre de TRAMA.
    title: (title) => `${title} - ${appName}`,
    // Busca el componente Vue solicitado por Laravel y le aplica el layout que
    // corresponde, igual que hace app.js en el navegador.
    resolve: resolveInertiaPage,
    // Crea la instancia Vue del servidor. A diferencia del cliente, acá no se
    // monta sobre un nodo real porque Node solo genera HTML.
    setup({ App, props, plugin }) {
        // Laravel comparte las rutas nombradas en page.props.ziggy para que
        // route() funcione también durante el renderizado del servidor.
        const ziggy = {
            ...page.props.ziggy,
            location: new URL(page.props.ziggy.location),
        };

        // ssr.js es la entrada del servidor, por eso usa createSSRApp.
        // No se llama a mount porque Node no tiene un elemento HTML real.
        return createSSRApp({ render: () => h(App, props) })
            .use(plugin)
            .use(ZiggyVue, ziggy);
    },
}));
