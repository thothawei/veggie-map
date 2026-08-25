<script setup lang="ts">
import { AGENT_STATUS_LABELS } from '../../labels';
import type { AiOfficeAgent } from '../../types';

defineProps<{ agent: AiOfficeAgent; taskCount?: number }>();

const emit = defineEmits<{ (event: 'open', agentId: number): void }>();
</script>

<template>
    <button type="button" class="agent-card" @click="emit('open', agent.id)">
        <span class="avatar" aria-hidden="true">{{ agent.avatar ?? agent.name.slice(0, 1) }}</span>
        <span class="body">
            <span class="name">{{ agent.name }}</span>
            <span class="role">{{ agent.role }}</span>
            <span class="model">{{ agent.model_provider }} · {{ agent.model_name }}</span>
        </span>
        <span class="right">
            <span class="status" :data-status="agent.status">
                {{ AGENT_STATUS_LABELS[agent.status] ?? agent.status }}
            </span>
            <span v-if="taskCount !== undefined" class="tasks">{{ taskCount }} / {{ agent.max_concurrency }}</span>
        </span>
    </button>
</template>

<style scoped>
.agent-card {
    width: 100%;
    display: flex;
    align-items: center;
    gap: 0.75rem;
    padding: 0.6rem 0.75rem;
    background: var(--ai-surface);
    border: 1px solid var(--ai-border);
    border-radius: 0.5rem;
    color: inherit;
    text-align: left;
    cursor: pointer;
}

.avatar {
    width: 2.25rem;
    height: 2.25rem;
    display: grid;
    place-items: center;
    background: var(--ai-bg);
    border-radius: 50%;
    font-size: 1.1rem;
}

.body {
    flex: 1;
    display: flex;
    flex-direction: column;
}

.name {
    font-weight: 600;
}

.role,
.model,
.tasks {
    font-size: 0.75rem;
    color: var(--ai-muted);
}

.right {
    display: flex;
    flex-direction: column;
    align-items: flex-end;
    gap: 0.2rem;
}

.status {
    font-size: 0.75rem;
    padding: 0.1rem 0.5rem;
    border-radius: 999px;
    border: 1px solid var(--ai-border);
}

.status[data-status='working'] {
    color: #7fd18f;
}

.status[data-status='error'] {
    color: #f2777a;
}

.status[data-status='waiting_review'] {
    color: #f6c344;
}

.status[data-status='offline'] {
    color: var(--ai-muted);
}
</style>
