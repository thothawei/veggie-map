<script setup lang="ts">
import { RouterLink } from 'vue-router';

defineProps<{ title: string }>();
</script>

<template>
    <div class="ai-office">
        <nav class="sub-nav">
            <RouterLink :to="{ name: 'ai-office' }">總覽</RouterLink>
            <RouterLink :to="{ name: 'ai-office-agents' }">Agent</RouterLink>
            <RouterLink :to="{ name: 'ai-office-approvals' }">核准</RouterLink>
            <RouterLink :to="{ name: 'ai-office-usage' }">用量</RouterLink>
        </nav>
        <h1>{{ title }}</h1>
        <slot />
    </div>
</template>

<style src="../theme.css"></style>

<style scoped>
.sub-nav {
    display: flex;
    gap: 1rem;
    margin-bottom: 0.75rem;
    font-size: 0.9rem;
}

.sub-nav a.router-link-active {
    font-weight: 700;
    text-decoration: underline;
}
</style>
