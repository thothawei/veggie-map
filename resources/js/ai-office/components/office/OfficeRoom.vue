<script setup lang="ts">
import AgentDesk from './AgentDesk.vue';
import type { AiOfficeAgent, AiOfficeTask } from '../../types';

defineProps<{
    title: string;
    agents: AiOfficeAgent[];
    /** agent id → 他現在跑的任務。沒有就不顯示，不要顯示「閒置」以外的猜測。 */
    tasksByAgent: Map<number, AiOfficeTask>;
}>();

const emit = defineEmits<{ (event: 'select', agentId: number): void }>();
</script>

<template>
    <section class="room">
        <h3>{{ title }}</h3>
        <div class="floor">
            <AgentDesk
                v-for="agent in agents"
                :key="agent.id"
                :agent="agent"
                :task="tasksByAgent.get(agent.id) ?? null"
                @select="emit('select', $event)"
            />
        </div>
    </section>
</template>

<style scoped>
.room {
    padding: 0.5rem;
    background: var(--ai-surface);
    /* 房間的牆：實線方框 + 內側地板色，全部用 CSS，沒有圖片。 */
    border: 2px solid var(--ai-border);
    border-radius: 0.25rem;
}

h3 {
    margin: 0 0 0.35rem;
    font-size: 0.75rem;
    letter-spacing: 0.08em;
    color: var(--ai-muted);
    text-transform: uppercase;
}

.floor {
    display: flex;
    flex-wrap: wrap;
    gap: 0.25rem;
    padding: 0.35rem;
    background:
        repeating-linear-gradient(
            90deg,
            var(--ai-bg) 0 1rem,
            #0e141b 1rem 2rem
        );
    border-radius: 0.2rem;
}
</style>
