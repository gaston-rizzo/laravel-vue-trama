<script setup>
/* ============================================================================
 * PAGE: Public/Privacy.vue
 * ============================================================================
 * Política de privacidad pública de TRAMA.
 * ============================================================================ */

import { Head } from '@inertiajs/vue3';
</script>

<template>
    <Head title="Privacidad" />

    <section class="public-info-page news-container">
        <p class="eyebrow">Sobre TRAMA</p>

        <h1>Privacidad</h1>

        <p class="public-info-intro">
            Esta política explica qué información utiliza TRAMA para operar las
            funciones del portal.
        </p>

        <div class="public-info-content">
            <section>
                <h2>Cuentas</h2>
                <p>
                    Al crear una cuenta se almacenan los datos necesarios para
                    identificarla, autenticarla y mantener su seguridad.
                </p>
            </section>

            <section>
                <h2>Comentarios</h2>
                <p>
                    Los comentarios, respuestas, reportes y acciones de moderación pueden conservarse para mantener 
                    el contexto de las conversaciones y un registro de las decisiones de moderación.
                </p>
            </section>

            <section>
                <h2>Cookies y analítica</h2>
                <p>
                    El sitio puede utilizar cookies técnicas y métricas
                    agregadas necesarias para sesión, funcionamiento y análisis
                    del uso del portal.
                </p>
            </section>

            <section>
                <h2>Consultas</h2>
                <p>
                    Podés utilizar la página de Contacto para comunicarte con nosotros por temas 
                    relacionados con el contenido del sitio, publicidad, tus datos o tu cuenta.
                </p>
            </section>
        </div>
    </section>
</template>