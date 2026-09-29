<script setup>
/* ============================================================================
 * LAYOUT: AuthNewsLayout.vue
 * ============================================================================
 *
 * Layout de autenticación personalizado.
 *
 * Define la estructura visual compartida por login, registro y recuperación de
 * contraseña dentro del sitio de TRAMA.
 * ============================================================================ */

import { Link } from '@inertiajs/vue3';
</script>

<template>
    <div class="auth-shell">
        <section class="auth-editorial">
            <Link :href="route('home')" class="brand-mark">
                <img :src="'/images/brand/logo.webp'" alt="" class="brand-icon" />
                <span>TRAMA</span>
            </Link>
            <div>                
                <h1>Noticias con criterio, datos y comunidad activa.</h1>
                <p class="auth-copy">
                    Ingresá para comentar noticias.
                </p>
            </div>
        </section>

        <section class="auth-panel">
            <slot />
        </section>
    </div>
</template>
