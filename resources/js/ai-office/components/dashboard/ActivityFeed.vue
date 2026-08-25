<script setup lang="ts">
import { formatEventTime } from '../../labels';
import type { AiOfficeActivity } from '../../types';
import type { StreamState } from '../../composables/useActivityStream';

defineProps<{ activities: AiOfficeActivity[]; state: StreamState; error?: string | null }>();

/**
 * 連線狀態要老實顯示。退回輪詢時畫面仍會更新，但延遲是幾秒不是即時——
 * 寫成「連線中」會讓人以為看到的是最新狀態。
 */
const STATE_LABELS: Record<StreamState, string> = {
    idle: '尚未連線',
    connecting: '連線中…',
    live: '即時',
    polling: '輪詢中（非即時）',
    stopped: '已停止',
};
</script>

<template>
    <section class="activity-feed">
        <header>
            <h2>事件流</h2>
            <span class="state" :data-state="state">{{ STATE_LABELS[state] }}</span>
        </header>

        <p v-if="error" class="error" role="alert">{{ error }}</p>
        <p v-if="activities.length === 0" class="hint">還沒有事件。</p>

        <ol v-else class="events">
            <li v-for="activity in activities" :key="activity.id">
                <span class="time">{{ formatEventTime(activity.created_at) }}</span>
                <span class="type">{{ activity.type }}</span>
                <span class="description">{{ activity.description }}</span>
            </li>
        </ol>
    </section>
</template>

<style scoped>
header {
    display: flex;
    align-items: baseline;
    justify-content: space-between;
    gap: 0.5rem;
}

h2 {
    margin: 0 0 0.5rem;
    font-size: 1.1rem;
}

.state {
    font-size: 0.75rem;
    padding: 0.15rem 0.5rem;
    border-radius: 999px;
    border: 1px solid var(--ai-border);
    color: var(--ai-muted);
}

.state[data-state='live'] {
    color: #7fd18f;
}

.state[data-state='polling'] {
    color: #f6c344;
}

.events {
    margin: 0;
    padding: 0;
    list-style: none;
    display: flex;
    flex-direction: column;
    gap: 0.35rem;
    max-height: 24rem;
    overflow-y: auto;
}

.events li {
    display: grid;
    grid-template-columns: 4.5rem 9rem 1fr;
    gap: 0.5rem;
    padding: 0.35rem 0.5rem;
    background: var(--ai-surface);
    border-radius: 0.375rem;
    font-size: 0.85rem;
}

@media (max-width: 640px) {
    .events li {
        grid-template-columns: 1fr;
        gap: 0.15rem;
    }
}

.time,
.type {
    color: var(--ai-muted);
    font-variant-numeric: tabular-nums;
}

.error {
    color: #f2777a;
    font-size: 0.8rem;
}

.hint {
    color: var(--ai-muted);
    font-size: 0.85rem;
}
</style>
