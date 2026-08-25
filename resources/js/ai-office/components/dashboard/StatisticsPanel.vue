<script setup lang="ts">
/**
 * 統計磚。數字一律由呼叫端從 API 資料算好傳進來（規格第 7／38／74 節：
 * 禁止 hardcode），這個元件只負責排版。
 */
defineProps<{ items: { label: string; value: number | string; tone?: 'default' | 'warn' | 'danger' }[] }>();
</script>

<template>
    <ul class="stats">
        <li v-for="item in items" :key="item.label" class="stat" :data-tone="item.tone ?? 'default'">
            <span class="value">{{ item.value }}</span>
            <span class="label">{{ item.label }}</span>
        </li>
    </ul>
</template>

<style scoped>
.stats {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(8rem, 1fr));
    gap: 0.75rem;
    margin: 0;
    padding: 0;
    list-style: none;
}

.stat {
    display: flex;
    flex-direction: column;
    gap: 0.25rem;
    padding: 0.75rem 1rem;
    background: var(--ai-surface);
    border: 1px solid var(--ai-border);
    border-radius: 0.5rem;
}

.value {
    font-size: 1.5rem;
    font-weight: 700;
}

.stat[data-tone='warn'] .value {
    color: #f6c344;
}

.stat[data-tone='danger'] .value {
    color: #f2777a;
}

.label {
    font-size: 0.8rem;
    color: var(--ai-muted);
}
</style>
