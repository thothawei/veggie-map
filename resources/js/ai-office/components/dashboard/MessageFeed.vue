<script setup lang="ts">
/**
 * 規格第 34 節：Agent 之間的往來訊息。
 *
 * 跟 ActivityFeed 的分工：Activity 是「系統發生了什麼」（沒有收件人），
 * Message 是「誰對誰說了什麼」。兩個面板分開，才看得出協作的線。
 */
import { formatEventTime } from '../../labels';
import type { AiOfficeMessage } from '../../types';

defineProps<{ messages: AiOfficeMessage[] }>();
</script>

<template>
    <section class="messages">
        <h2>Agent 對話</h2>

        <ul v-if="messages.length" class="items">
            <li v-for="message in messages" :key="message.id">
                <span class="time">{{ formatEventTime(message.created_at) }}</span>
                <span class="who">{{ message.from?.name ?? '—' }} → {{ message.to?.name ?? '—' }}</span>
                <span class="content">{{ message.content }}</span>
            </li>
        </ul>
        <p v-else class="hint">還沒有 Agent 之間的訊息。</p>
    </section>
</template>

<style scoped>
.items {
    list-style: none;
    margin: 0;
    padding: 0;
    display: grid;
    gap: 0.4rem;
}

.items li {
    display: grid;
    grid-template-columns: auto auto 1fr;
    gap: 0.5rem;
    align-items: baseline;
    font-size: 0.85rem;
}

.time {
    color: var(--ai-muted);
    font-variant-numeric: tabular-nums;
}

.who {
    color: var(--ai-accent, #4fd1c5);
    white-space: nowrap;
}

.hint {
    color: var(--ai-muted);
    font-size: 0.85rem;
}
</style>
