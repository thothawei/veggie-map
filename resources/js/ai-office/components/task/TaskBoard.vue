<script setup lang="ts">
import { computed } from 'vue';
import TaskCard from './TaskCard.vue';
import { TASK_STATUS_LABELS } from '../../labels';
import { TASK_STATUSES, type AiOfficeAgent, type AiOfficeTask, type TaskStatus } from '../../types';

const props = defineProps<{
    tasks: AiOfficeTask[];
    agents: AiOfficeAgent[];
    selectedId?: number | null;
}>();

const emit = defineEmits<{ (event: 'select', taskId: number): void }>();

/**
 * 十個狀態全部開一欄會擠到看不懂，但欄位也不能寫死成「前端自己定義的三欄」——
 * 狀態是後端的，這裡只做分組：空欄位不顯示，有任務的照 TASK_STATUSES 的順序排。
 */
const columns = computed(() => TASK_STATUSES
    .map((status: TaskStatus) => ({
        status,
        label: TASK_STATUS_LABELS[status],
        tasks: props.tasks.filter((task) => task.status === status),
    }))
    .filter((column) => column.tasks.length > 0));

const agentNames = computed(() => new Map(props.agents.map((agent) => [agent.id, agent.name])));
</script>

<template>
    <div class="task-board">
        <p v-if="columns.length === 0" class="hint">這個專案還沒有任務。CEO 規劃完成後會出現在這裡。</p>

        <div v-else class="columns">
            <section v-for="column in columns" :key="column.status" class="column" :data-status="column.status">
                <header>
                    <h3>{{ column.label }}</h3>
                    <span class="count">{{ column.tasks.length }}</span>
                </header>
                <TaskCard
                    v-for="task in column.tasks"
                    :key="task.id"
                    :task="task"
                    :agent-name="task.assigned_agent_id ? agentNames.get(task.assigned_agent_id) : null"
                    :selected="task.id === selectedId"
                    @select="emit('select', $event)"
                />
            </section>
        </div>
    </div>
</template>

<style scoped>
.columns {
    display: flex;
    gap: 0.75rem;
    overflow-x: auto;
    padding-bottom: 0.5rem;
}

.column {
    flex: 0 0 14rem;
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
    padding: 0.5rem;
    background: var(--ai-bg);
    border: 1px solid var(--ai-border);
    border-radius: 0.5rem;
}

.column header {
    display: flex;
    align-items: baseline;
    justify-content: space-between;
}

h3 {
    margin: 0;
    font-size: 0.85rem;
}

.count,
.hint {
    color: var(--ai-muted);
    font-size: 0.8rem;
}

.column[data-status='failed'] h3 {
    color: #f2777a;
}

.column[data-status='waiting_review'] h3 {
    color: #f6c344;
}
</style>
