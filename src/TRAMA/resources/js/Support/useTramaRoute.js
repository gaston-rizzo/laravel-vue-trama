/* ============================================================================
 * SUPPORT: useTramaRoute.js
 * ============================================================================
 *
 * Crea una función route() ligada a las props Ziggy de la petición Inertia
 * actual. Esto evita depender de estado global de Node durante SSR y mantiene
 * el mismo comportamiento en el navegador.
 * ============================================================================ */

import { usePage } from '@inertiajs/vue3';
import { route as ziggyRoute } from 'ziggy-js';

export function useTramaRoute() {
    const page = usePage();

    return (name, params, absolute) => {
        const ziggy = page.props.ziggy;

        if (!ziggy) {
            return ziggyRoute(name, params, absolute);
        }

        const config = { ...ziggy };

        if (ziggy.location) {
            config.location = ziggy.location instanceof URL
                ? ziggy.location
                : new URL(ziggy.location);
        }

        return ziggyRoute(name, params, absolute, config);
    };
}
