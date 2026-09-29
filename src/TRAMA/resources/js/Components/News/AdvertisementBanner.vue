<script setup>
/* ============================================================================
 * COMPONENT: AdvertisementBanner.vue
 * ============================================================================
 *
 * Muestra una publicidad real cargada desde Laravel.
 *
 * Recibe los datos de la tabla advertisements ya preparados por
 * AdvertisementResource: marca, imagen pública, destino y URL interna de
 * medición. El enlace abre directamente el destino del anuncio y el click se
 * registra en segundo plano, evitando mostrar al usuario una página intermedia
 * mientras Laravel guarda la métrica.
 *
 * Si no recibe publicidad, el componente no pinta nada; de esa forma nunca
 * queda visible un recuadro falso o placeholder en el sitio.
 * ============================================================================ */

import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';

// Props del banner:
// advertisement contiene marca, imagen, destino y URL de medición; variant
// indica en qué ubicación se pinta para aplicar el tamaño correcto.
const props = defineProps({
    advertisement: {
        type: Object,
        default: null,
    },
    variant: {
        type: String,
        required: true,
    },
    priority: {
        type: Boolean,
        default: false,
    },
});

// Clases visuales que fijan el tamaño correspondiente a cada ubicación real.
const variantClasses = {
    header: 'header-ad',
    sidebarTop: 'article-ad article-ad-short',
    sidebarBottom: 'article-ad article-ad-tall',
};

// Clase CSS final aplicada al enlace del banner.
const bannerClass = computed(() => variantClasses[props.variant] || '');

const bannerElement = ref(null);
const imageElement = ref(null);

// Evita mostrar el texto alternativo si el navegador no puede descargar la imagen.
const hasBrokenImage = ref(false);

const imageLoaded = ref(false);
const impressionRegistered = ref(false);

let impressionObserver = null;

// Los banners visibles en el primer pantallazo, como el header, se cargan antes.
const loadingMode = computed(() => (props.priority ? 'eager' : 'lazy'));

// Refuerza la prioridad de descarga en navegadores que soportan fetchpriority.
const fetchPriority = computed(() => (props.priority ? 'high' : 'auto'));

// Marca la imagen como inválida cuando el navegador no puede cargarla.
//
// Al cambiar hasBrokenImage a true, el v-if del template deja de renderizar
// completamente el banner para evitar mostrar un anuncio roto o vacío.
function hideBrokenBanner() {
    hasBrokenImage.value = true;
    disconnectImpressionObserver();
}

function disconnectImpressionObserver() {
    if (!impressionObserver) {
        return;
    }

    impressionObserver.disconnect();
    impressionObserver = null;
}

function registerImpression() {
    const impressionUrl = props.advertisement?.impression_url;

    if (
        impressionRegistered.value
        || !imageLoaded.value
        || hasBrokenImage.value
        || !impressionUrl
        || typeof window === 'undefined'
    ) {
        return;
    }

    impressionRegistered.value = true;
    disconnectImpressionObserver();

    void window.fetch(impressionUrl, {
        method: 'GET',
        credentials: 'same-origin',
        cache: 'no-store',
        keepalive: true,
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
        },
    }).catch(() => {
        // La medicion nunca debe afectar la lectura ni ocultar el banner.
    });
}

function observeBannerVisibility() {
    if (
        impressionRegistered.value
        || !imageLoaded.value
        || hasBrokenImage.value
        || typeof window === 'undefined'
    ) {
        return;
    }

    const element = bannerElement.value;

    if (!element) {
        return;
    }

    if (!('IntersectionObserver' in window)) {
        registerImpression();
        return;
    }

    disconnectImpressionObserver();

    impressionObserver = new IntersectionObserver(
        (entries) => {
            const isVisible = entries.some(
                (entry) => entry.isIntersecting && entry.intersectionRatio >= 0.5
            );

            if (isVisible) {
                registerImpression();
            }
        },
        {
            threshold: [0.5],
        }
    );

    impressionObserver.observe(element);
}

function markImageLoaded() {
    imageLoaded.value = true;

    void nextTick(() => {
        observeBannerVisibility();
    });
}

function markAlreadyLoadedImage() {
    const image = imageElement.value;

    if (image?.complete && image.naturalWidth > 0) {
        markImageLoaded();
    }
}

/**
 * Registra el click sin convertir el endpoint de medición en una navegación.
 *
 * El <a> conserva como href el destino real del anuncio, por lo que el
 * navegador abre la landing inmediatamente. Esta petición se ejecuta en
 * paralelo y no bloquea ni retrasa la nueva pestaña.
 *
 * keepalive permite que el navegador intente completar la petición aunque la
 * página original cambie de estado. Los errores de medición no se muestran al
 * visitante porque nunca deben impedir que pueda abrir el anuncio.
 */
function registerClick() {
    // Obtiene la URL interna que Laravel expone para registrar el click
    // correspondiente a esta publicidad.
    const clickUrl = props.advertisement?.click_url;

    // Si no existe una URL de tracking o el código se está ejecutando fuera del
    // navegador, no se intenta registrar la métrica.
    if (!clickUrl || typeof window === 'undefined') {
        return;
    }

    // Envía la petición de tracking en segundo plano.
    //
    // El navegador continúa inmediatamente con la apertura del destino real del
    // anuncio; esta petición no participa de esa navegación ni la bloquea.
    void window.fetch(clickUrl, {
        // El endpoint solamente necesita registrar el click.
        method: 'GET',
        // Conserva las credenciales de la misma aplicación Laravel.
        credentials: 'same-origin',
        // Evita reutilizar una respuesta almacenada en caché para una métrica nueva.
        cache: 'no-store',
        // Intenta completar la petición aunque el usuario navegue o cambie de página
        // inmediatamente después de hacer click en el banner.
        keepalive: true,
        headers: {
            // El controller devuelve una respuesta HTTP vacía y no una página HTML.
            Accept: 'application/json',
            // Identifica la petición como una solicitud realizada desde JavaScript.
            'X-Requested-With': 'XMLHttpRequest',
        },
    }).catch(() => {
        // El tracking es secundario: un fallo al registrar la métrica nunca debe
        // bloquear ni reemplazar la navegación hacia el destino del anunciante.
    });
}

// Al cambiar de banner por rotación, se vuelve a permitir mostrar la nueva imagen.
watch(
    () => props.advertisement?.id,
    () => {
        hasBrokenImage.value = false;
        imageLoaded.value = false;
        impressionRegistered.value = false;
        disconnectImpressionObserver();

        void nextTick(() => {
            markAlreadyLoadedImage();
        });
    }
);

onMounted(() => {
    markAlreadyLoadedImage();
});

onBeforeUnmount(() => {
    disconnectImpressionObserver();
});
</script>

<template>
    <a
        v-if="advertisement && !hasBrokenImage"
        ref="bannerElement"
        :href="advertisement.target_url"
        :class="bannerClass"
        target="_blank"
        rel="noopener noreferrer"
        :aria-label="`Publicidad de ${advertisement.brand}`"
        @click="registerClick"
        @auxclick.middle="registerClick"
    >
        <img
            ref="imageElement"
            :src="advertisement.image_url"
            alt=""
            :loading="loadingMode"
            :fetchpriority="fetchPriority"
            decoding="async"
            @load="markImageLoaded"
            @error="hideBrokenBanner"
        />
    </a>
</template>
