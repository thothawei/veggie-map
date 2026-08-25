<script setup lang="ts">
import { AGENT_STATUS_LABELS } from '../../labels';
import type { AgentStatus } from '../../types';

defineProps<{ status: AgentStatus; compact?: boolean }>();
</script>

<template>
    <span class="status-badge" :data-status="status" :class="{ compact }">
        <span class="dot" aria-hidden="true" />
        <span v-if="!compact" class="text">{{ AGENT_STATUS_LABELS[status] ?? status }}</span>
        <span v-else class="sr-only">{{ AGENT_STATUS_LABELS[status] ?? status }}</span>
    </span>
</template>

<style scoped>
.status-badge {
    display: inline-flex;
    align-items: center;
    gap: 0.3rem;
    font-size: 0.7rem;
    color: var(--ai-muted);
}

.dot {
    width: 0.5rem;
    height: 0.5rem;
    background: var(--ai-muted);
    /* 像素風：方塊不是圓點，也不要陰影。 */
    box-shadow: none;
}

.status-badge[data-status='working'] .dot {
    background: #7fd18f;
    animation: blink 1.2s steps(2, end) infinite;
}

.status-badge[data-status='working'] .text {
    color: #7fd18f;
}

.status-badge[data-status='waiting_review'] .dot,
.status-badge[data-status='waiting_review'] .text {
    background: #f6c344;
    color: #f6c344;
}

.status-badge[data-status='waiting_review'] .text {
    background: none;
}

.status-badge[data-status='error'] .dot,
.status-badge[data-status='error'] .text {
    background: #f2777a;
    color: #f2777a;
}

.status-badge[data-status='error'] .text {
    background: none;
}

.status-badge[data-status='offline'] {
    opacity: 0.6;
}

.sr-only {
    position: absolute;
    width: 1px;
    height: 1px;
    padding: 0;
    margin: -1px;
    overflow: hidden;
    clip: rect(0, 0, 0, 0);
    white-space: nowrap;
    border: 0;
}

@keyframes blink {
    50% {
        opacity: 0.25;
    }
}

/* 會動的東西對前庭系統敏感的人是障礙，系統設定說不要動就不要動。 */
@media (prefers-reduced-motion: reduce) {
    .status-badge[data-status='working'] .dot {
        animation: none;
    }
}
</style>
