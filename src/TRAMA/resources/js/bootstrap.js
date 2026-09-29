/* ============================================================================
 * FRONTEND: bootstrap.js
 * ============================================================================
 *
 * Configura Axios y las dependencias HTTP compartidas del frontend.
 *
 * Define cabeceras y comportamiento base para solicitudes al backend de Laravel.
 * ============================================================================ */

import axios from 'axios';

// Cabecera estándar para que Laravel identifique peticiones Ajax del frontend.
axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';

// En el navegador se mantiene window.axios para los componentes que hacen
// pedidos JSON puntuales. Durante SSR no existe window; por eso se protege la
// asignación y Node puede importar este archivo sin romper el render servidor.
if (typeof window !== 'undefined') {
    window.axios = axios;
}

export default axios;
