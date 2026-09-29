<script setup>
/* ============================================================================
 * PAGE: Public/About.vue
 * ============================================================================
 * Presentación pública del medio editorial TRAMA.
 * ============================================================================ */

import { Head } from '@inertiajs/vue3';
</script>

<template>
    <Head title="Quiénes somos" />

    <section class="public-info-page news-container">
        <p class="eyebrow">Sobre TRAMA</p>

        <h1>Quiénes somos</h1>

        <p class="public-info-intro">
            TRAMA es un medio digital dedicado a informar con claridad, contexto
            y una mirada editorial enfocada en los hechos.
        </p>

        <div class="public-info-content">
            <section>
                <h2>Nuestro enfoque</h2>
                <p>
                    Trabajamos las noticias priorizando datos, fuentes, contexto
                    y análisis para ofrecer una lectura clara de los hechos más
                    relevantes.
                </p>
            </section>

            <section>
                <h2>Cobertura</h2>
                <p>
                    El portal organiza su cobertura en política, economía,
                    tecnología, deportes, mundo y cultura, con una estructura
                    pensada para crecer sin perder claridad.
                </p>
            </section>

            <section>
                <h2>Nuestra misión</h2>
                <p>
                    Buscamos que cada publicación aporte información verificable,
                    contexto suficiente y una explicación comprensible para que
                    nuestros lectores puedan seguir la actualidad con criterio.
                </p>
            </section>
        </div>
    </section>
</template>