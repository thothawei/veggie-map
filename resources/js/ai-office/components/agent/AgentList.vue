<script setup lang="ts">
import AgentCard from './AgentCard.vue';
import type { AiOfficeAgent, AiOfficeTask } from '../../types';
import { computed } from 'vue';

const props = defineProps<{ agents: AiOfficeAgent[]; tasks?: AiOfficeTask[]; loading?: boolean }>();

const emit = defineEmits<{ (event: 'open', agentId: number): void }>();

/**
 * 「手上幾個任務」由任務清單算，不另外打 API：列表模式的 AgentResource 不含
 * `active_task_count`（那是詳細模式才有的）。沒有傳 tasks 就不顯示，不要顯示 0
 * ——0 跟「不知道」是兩件事。
 */
const runningPerAgent = computed(() => {
    if (!props.tasks) {
        return null;
    }

    const counts = new Map<number, number>();

    props.tasks
        .filter((task) => task.status === 'running' && task.assigned_agent_id !== null)
        .forEach((task) => {
            const id = task.assigned_agent_id as number;
            counts.set(id, (counts.get(id) ?? 0) + 1);
        });

    return counts;
});
</script>

<template>
    <section class="agent-list">
        <h2>Agent</h2>
        <p v-if="loading" class="hint">載入中…</p>
        <p v-else-if="agents.length === 0" class="hint">還沒有 Agent。跑 `db:seed --class=AgentSeeder` 建立初始團隊。</p>
        <div v-else class="cards">
            <AgentCard
                v-for="agent in agents"
                :key="agent.id"
                :agent="agent"
                :task-count="runningPerAgent ? (runningPerAgent.get(agent.id) ?? 0) : undefined"
                @open="emit('open', $event)"
            />
        </div>
    </section>
</template>

<style scoped>
h2 {
    margin: 0 0 0.5rem;
    font-size: 1.1rem;
}

.cards {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(16rem, 1fr));
    gap: 0.5rem;
}

.hint {
    color: var(--ai-muted);
    font-size: 0.85rem;
}
</style>
