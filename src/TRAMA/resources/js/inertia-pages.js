/* ============================================================================
 * FRONTEND: inertia-pages.js
 * ============================================================================
 *
 * Resuelve la única página raíz que Laravel entrega a Inertia: Trama.vue.
 *
 * Las pantallas reales del portal se cargan de forma diferida dentro de
 * Trama.vue. Mantener el resolver limitado a esa raíz evita registrar como
 * páginas Inertia independientes todos los módulos Public, Auth y Admin.
 * ============================================================================ */

import PersistentInertiaLayout from './Layouts/PersistentInertiaLayout.vue';

/**
 * Carga la página raíz de TRAMA y le asigna el layout persistente que decide si
 * una pantalla pública debe usar PublicNewsLayout.
 */
export async function resolveInertiaPage(name) {
    if (name !== 'Trama') {
        throw new Error(`Página Inertia no reconocida: ${name}`);
    }

    const page = await import('./Pages/Trama.vue');

    page.default.layout = PersistentInertiaLayout;

    return page;
}
