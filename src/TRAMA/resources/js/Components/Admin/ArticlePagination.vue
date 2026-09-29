<script setup>
/* ============================================================================
 * COMPONENT: ArticlePagination.vue
 * ============================================================================
 *
 * Muestra la paginación numerada utilizada por el listado editorial de
 * noticias.
 *
 * Recibe la información generada por Laravel mediante LengthAwarePaginator:
 *
 *      - página actual;
 *      - última página disponible;
 *      - cantidad total de resultados;
 *      - rango de noticias mostrado;
 *      - cantidad actual de resultados por página;
 *      - enlaces Anterior, números de página y Siguiente.
 *
 * Cada enlace se navega mediante Inertia. La tabla se actualiza sin recargar
 * completamente el navegador y conserva los parámetros presentes en la URL,
 * como búsqueda, filtros, ordenamiento y cantidad de registros por página.
 *
 * El componente también puede mostrar un selector opcional para cambiar la
 * cantidad de resultados por página.
 *
 *  Ejemplo:
 *
 *      "Mostrando 26 a 50 de 238 noticias"
 *
 * Los estilos se encuentran en resources/css/admin/editorial-listing.css y utilizan el prefijo
 * editorial-pagination para evitar conflictos con otras paginaciones.
 * ============================================================================ */

import { computed, nextTick, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { ChevronDown, ChevronLeft, ChevronRight } from '@lucide/vue';

/*
 * Propiedades recibidas desde el componente que utiliza la paginación.
 */
const props = defineProps({
    /*
     * Información de paginación generada por Laravel.
     */
    pagination: {
        type: Object,
        required: true,
    },

    /*
     * Cantidad de resultados mostrados actualmente por página.
     *
     * Es opcional porque otros listados pueden utilizar Pagination.vue sin
     * mostrar el selector de cantidad.
     */
    perPage: {
        type: [Number, String],
        default: null,
    },

    /*
     * Cantidades disponibles dentro del selector.
     *
     * Cuando el arreglo está vacío, el selector no se muestra.
     *
     * Ejemplo:
     *
     * [15, 25, 50, 100]
     */
    perPageOptions: {
        type: Array,
        default: () => [],
    },
});

/*
 * Eventos enviados al componente que utiliza Pagination.vue.
 */
const emit = defineEmits([
    'update:perPage',
]);

/*
 * Indica si el selector de cantidad por página se encuentra abierto.
 *
 * Se utiliza para girar visualmente la flecha del combo.
 */
const isPerPageOpen = ref(false);

/*
 * Laravel coloca el enlace hacia la página anterior en la primera posición
 * del arreglo links.
 */
const previousLink = computed(() => {
    return props.pagination.links?.[0] ?? null;
});

/*
 * Laravel coloca el enlace hacia la página siguiente en la última posición
 * del arreglo links.
 */
const nextLink = computed(() => {
    const links = props.pagination.links ?? [];

    return links.length > 0
        ? links[links.length - 1]
        : null;
});

/*
 * Excluye los enlaces Anterior y Siguiente para conservar únicamente los
 * números de página y los posibles separadores con puntos suspensivos.
 */
const pageLinks = computed(() => {
    const links = props.pagination.links ?? [];

    return links.slice(1, -1);
});

/*
 * Determina la cantidad actualmente seleccionada.
 *
 * Primero utiliza la propiedad perPage recibida desde la página. Si esa
 * propiedad no fue enviada, utiliza pagination.per_page como valor alternativo.
 */
const currentPerPage = computed(() => {
    return Number(
        props.perPage
        ?? props.pagination.per_page
        ?? 25,
    );
});

/*
 * Determina si debe mostrarse el selector de cantidad por página.
 *
 * El selector solamente aparece cuando el componente recibe al menos una
 * opción disponible.
 */
const hasPerPageSelector = computed(() => {
    return props.perPageOptions.length > 0;
});

/*
 * Determina si debe mostrarse toda la barra inferior.
 *
 * La barra aparece cuando:
 *
 * - existe más de una página; o
 * - existe el selector de cantidad y hay resultados.
 *
 * La segunda condición permite seguir mostrando "Mostrar 25" aunque todos los
 * resultados entren dentro de una sola página.
 */
const shouldShowPagination = computed(() => {
    return props.pagination.last_page > 1
        || (
            hasPerPageSelector.value
            && props.pagination.total > 0
        );
});

/*
 * Gira la flecha hacia arriba cuando el usuario abre el selector.
 */
function openPerPageSelector() {
    isPerPageOpen.value = true;
}

/*
 * Devuelve la flecha hacia abajo cuando el selector pierde el foco.
 */
function closePerPageSelector() {
    isPerPageOpen.value = false;
}

/*
 * Obtiene la cantidad seleccionada y la envía al componente que utiliza
 * Pagination.vue.
 *
 * El valor de un elemento select llega como texto, por eso se convierte
 * explícitamente a número.
 */
function changePerPage(event) {
    /*
     * Después de seleccionar una opción, vuelve a colocar la flecha
     * apuntando hacia abajo.
     */
    isPerPageOpen.value = false;

    emit(
        'update:perPage',
        Number(event.target.value),
    );
}

/*
 * Navega hacia otra página conservando exactamente la posición de la ventana.
 *
 * preserveScroll debería impedir que Inertia reinicie el desplazamiento.
 * Además, se guarda y restaura manualmente la posición para mantener el mismo
 * resultado en navegadores que procesan el reemplazo de contenido de manera
 * diferente.
 */
function visitPage(url) {
    /*
     * No ejecuta ninguna navegación cuando Laravel no proporcionó una URL.
     *
     * Esto ocurre, por ejemplo, en los botones Anterior o Siguiente cuando el
     * usuario ya se encuentra en el extremo correspondiente.
     */
    if (! url) {
        return;
    }

    /*
     * Guarda la posición horizontal y vertical antes de iniciar la navegación.
     */
    const scrollX = window.scrollX;
    const scrollY = window.scrollY;

    router.visit(url, {
        method: 'get',
        preserveState: true,
        preserveScroll: true,

        /*
         * Espera a que Vue actualice las filas de la tabla y después devuelve
         * la ventana a la posición que tenía antes de cambiar de página.
         */
        onSuccess: async () => {
            await nextTick();

            /*
             * Chrome puede aplicar el desplazamiento suave global antes de
             * restaurar la posición y generar un movimiento visible.
             *
             * Se desactiva temporalmente para mover la ventana de forma
             * inmediata.
             */
            const documentElement = document.documentElement;

            const previousScrollBehavior =
                documentElement.style.scrollBehavior;

            documentElement.style.scrollBehavior = 'auto';

            window.scrollTo({
                left: scrollX,
                top: scrollY,
                behavior: 'auto',
            });

            /*
             * Recupera el comportamiento de desplazamiento que tenía la página
             * antes de realizar la navegación.
             */
            documentElement.style.scrollBehavior =
                previousScrollBehavior;
        },
    });
}
</script>

<template>
    <nav
        v-if="shouldShowPagination"
        class="editorial-pagination"
        aria-label="Paginación del listado de noticias"
    >
        <!-- Cantidad de noticias mostradas dentro de la página actual. -->
        <p class="editorial-pagination__summary">
            Mostrando
            <strong>{{ pagination.from }}</strong>
            a
            <strong>{{ pagination.to }}</strong>
            de
            <strong>{{ pagination.total }}</strong>
            noticias
        </p>

        <!-- Controles utilizados para cambiar entre las páginas disponibles. -->
        <div class="editorial-pagination__controls">
            <!-- Navegación hacia la página anterior. -->
            <a
                v-if="previousLink?.url"
                :href="previousLink.url"
                class="editorial-pagination__direction"
                aria-label="Ir a la página anterior"
                @click.prevent="visitPage(previousLink.url)"
            >
                <ChevronLeft :size="17" />

                <span>Anterior</span>
            </a>

            <!-- Estado deshabilitado cuando se encuentra en la primera página. -->
            <span
                v-else
                class="
                    editorial-pagination__direction
                    editorial-pagination__direction--disabled
                "
                aria-disabled="true"
            >
                <ChevronLeft :size="17" />

                <span>Anterior</span>
            </span>

            <!-- Números de página y posibles separadores. -->
            <div class="editorial-pagination__pages">
                <template
                    v-for="(link, index) in pageLinks"
                    :key="`${index}-${link.label}-${link.url ?? 'separator'}`"
                >
                    <!-- Enlace correspondiente a una página disponible. -->
                    <a
                        v-if="link.url"
                        :href="link.url"
                        class="editorial-pagination__page"
                        :class="{
                            'editorial-pagination__page--active': link.active,
                        }"
                        :aria-label="`Ir a la página ${link.label}`"
                        :aria-current="link.active ? 'page' : undefined"
                        @click.prevent="visitPage(link.url)"
                    >
                        {{ link.label }}
                    </a>

                    <!--
                        Laravel genera un elemento sin URL cuando necesita
                        mostrar puntos suspensivos entre páginas alejadas.
                    -->
                    <span
                        v-else
                        class="editorial-pagination__ellipsis"
                        aria-hidden="true"
                    >
                        {{ link.label }}
                    </span>
                </template>
            </div>

            <!-- Navegación hacia la página siguiente. -->
            <a
                v-if="nextLink?.url"
                :href="nextLink.url"
                class="
                    editorial-pagination__direction
                    editorial-pagination__direction--next
                "
                aria-label="Ir a la página siguiente"
                @click.prevent="visitPage(nextLink.url)"
            >
                <span>Siguiente</span>

                <ChevronRight :size="17" />
            </a>

            <!-- Estado deshabilitado cuando se encuentra en la última página. -->
            <span
                v-else
                class="
                    editorial-pagination__direction
                    editorial-pagination__direction--disabled
                "
                aria-disabled="true"
            >
                <span>Siguiente</span>

                <ChevronRight :size="17" />
            </span>
        </div>

        <!--
            Selector opcional ubicado en el extremo derecho de la barra.

            Solamente se muestra cuando el componente recibe opciones mediante
            perPageOptions.
        -->
        <div
            v-if="hasPerPageSelector"
            class="
                per-page-control
                editorial-pagination__per-page
            "
        >
            <span>Mostrar</span>

            <span
                class="editorial-pagination__select-wrapper"
                :class="{
                    'editorial-pagination__select-wrapper--open':
                        isPerPageOpen,
                }"
            >
                <select
                    :value="currentPerPage"
                    aria-label="Cantidad de noticias por página"
                    @pointerdown="openPerPageSelector"
                    @blur="closePerPageSelector"
                    @change="changePerPage"
                >
                    <option
                        v-for="option in perPageOptions"
                        :key="option"
                        :value="option"
                    >
                        {{ option }}
                    </option>
                </select>

                <ChevronDown
                    class="editorial-pagination__select-icon"
                    :size="16"
                    aria-hidden="true"
                />
            </span>
        </div>
    </nav>
</template>
