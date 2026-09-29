<script setup>
import { computed } from 'vue';
import ArticleCard from '@/Components/News/ArticleCard.vue';

const props = defineProps({
    articles: {
        type: Object,
        required: true,
    },
});

const articleCount = computed(() => Number(props.articles?.data?.length || 0));
const expectedCount = computed(() => Number(props.articles?.meta?.per_page || 0));
</script>

<template>
    <div
        class="featured-grid paginated-news-grid"
        :data-article-count="articleCount"
        :data-expected-count="expectedCount"
    >
        <ArticleCard
            v-for="article in articles.data"
            :key="article.id"
            :article="article"
        />
    </div>
</template>
