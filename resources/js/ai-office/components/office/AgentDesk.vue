<script setup lang="ts">
import AgentCharacter from './AgentCharacter.vue';
import AgentStatusBadge from './AgentStatusBadge.vue';
import type { AiOfficeAgent, AiOfficeTask } from '../../types';

defineProps<{ agent: AiOfficeAgent; task?: AiOfficeTask | null }>();

const emit = defineEmits<{ (event: 'select', agentId: number): void }>();
</script>

<template>
    <button
        type="button"
        class="desk"
        :data-status="agent.status"
        :aria-label="`${agent.name}${task ? `，正在處理 ${task.title}` : ''}`"
        @click="emit('select', agent.id)"
    >
        <AgentCharacter :name="agent.name" :role="agent.role" :status="agent.status" />

        <!-- 桌子與螢幕也是方塊，不用點陣圖（規格第 45 節）。 -->
        <svg class="furniture" viewBox="0 0 24 12" width="48" height="24" shape-rendering="crispEdges" aria-hidden="true">
            <rect x="0" y="6" width="24" height="2" fill="#39414d" />
            <rect x="2" y="8" width="2" height="4" fill="#2b2f36" />
            <rect x="20" y="8" width="2" height="4" fill="#2b2f36" />
            <rect x="14" y="1" width="8" height="5" fill="#2b2f36" />
            <rect class="screen" x="15" y="2" width="6" height="3" fill="#26313d" />
        </svg>

        <span class="name">{{ agent.name }}</span>
        <AgentStatusBadge :status="agent.status" />
        <span v-if="task" class="task">{{ task.title }}</span>
    </button>
</template>

<style scoped>
.desk {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 0.15rem;
    padding: 0.5rem 0.4rem;
    background: transparent;
    border: 1px solid transparent;
    border-radius: 0.375rem;
    color: inherit;
    cursor: pointer;
    width: 7.5rem;
}

.desk:hover,
.desk:focus-visible {
    border-color: var(--ai-border);
    background: var(--ai-surface);
}

/* 桌子往上疊在小人身上一點，看起來才是「坐在桌前」而不是「站在桌子上面」。 */
.furniture {
    margin-top: -0.75rem;
}

.name {
    font-size: 0.75rem;
    font-weight: 600;
}

.task {
    font-size: 0.7rem;
    color: var(--ai-muted);
    max-width: 100%;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

/* 螢幕亮著＝這個人真的在跑任務。顏色跟狀態同一個來源，不會出現「小人在打字但螢幕是暗的」。 */
.desk[data-status='working'] .screen {
    fill: #2f855a;
    animation: flicker 1.6s steps(2, end) infinite;
}

.desk[data-status='waiting_review'] .screen {
    fill: #f6c344;
}

.desk[data-status='error'] .screen {
    fill: #f2777a;
}

@keyframes flicker {
    50% {
        opacity: 0.55;
    }
}

@media (prefers-reduced-motion: reduce) {
    .desk[data-status='working'] .screen {
        animation: none;
    }
}
</style>
