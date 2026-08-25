<script setup lang="ts">
import type { AiOfficeTask } from '../../types';

defineProps<{ task: AiOfficeTask; agentName?: string | null; selected?: boolean }>();

const emit = defineEmits<{ (event: 'select', taskId: number): void }>();
</script>

<template>
    <button
        type="button"
        class="task-card"
        :class="{ selected }"
        :aria-pressed="selected ? 'true' : 'false'"
        @click="emit('select', task.id)"
    >
        <span class="title">{{ task.title }}</span>
        <span class="meta">
            <span class="agent">{{ agentName ?? '未指派' }}</span>
            <span class="priority">P{{ task.priority }}</span>
            <span v-if="task.retry_count > 0" class="retry">重試 {{ task.retry_count }}/{{ task.max_retries }}</span>
        </span>
    </button>
</template>

<style scoped>
.task-card {
    width: 100%;
    display: flex;
    flex-direction: column;
    gap: 0.35rem;
    padding: 0.5rem 0.6rem;
    background: var(--ai-surface);
    border: 1px solid var(--ai-border);
    border-radius: 0.375rem;
    color: inherit;
    text-align: left;
    cursor: pointer;
}

.task-card.selected {
    border-color: #7fd18f;
}

.title {
    font-size: 0.9rem;
    font-weight: 600;
}

.meta {
    display: flex;
    gap: 0.5rem;
    font-size: 0.75rem;
    color: var(--ai-muted);
}

.retry {
    color: #f6c344;
}
</style>
