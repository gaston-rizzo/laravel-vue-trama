<script setup>
/* ============================================================================
 * PAGE: Public/AdvertiserDemo.vue
 * ============================================================================
 * Landing independiente para destinos publicitarios alojados por TRAMA.
 * No utiliza header, navegación, banners ni footer del portal para simular
 * correctamente un destino externo.
 * ============================================================================ */
import { Head } from '@inertiajs/vue3';
defineProps({ advertiser: { type: Object, required: true } });
</script>
<template>
    <Head :title="advertiser.name" />
    <main class="advertiser-demo-page">
        <section class="advertiser-demo-card">
            <p class="eyebrow">{{ advertiser.eyebrow }}</p>
            <h1>{{ advertiser.name }}</h1>
            <h2>{{ advertiser.headline }}</h2>
            <p class="advertiser-demo-copy">{{ advertiser.description }}</p>
            <img :src="advertiser.image" :alt="`Publicidad de ${advertiser.name}`" />
            <ul><li v-for="benefit in advertiser.benefits" :key="benefit">{{ benefit }}</li></ul>
            <span class="advertiser-demo-cta">{{ advertiser.cta }}</span>
        </section>
    </main>
</template>
