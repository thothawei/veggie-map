<script setup lang="ts">
import { reactive, ref } from 'vue';
import { PROJECT_STATUS_LABELS } from '../../labels';
import type { AiOfficeProject } from '../../types';

defineProps<{ projects: AiOfficeProject[]; loading?: boolean; canCreate?: boolean }>();

const emit = defineEmits<{
    (event: 'create', payload: { name: string; description: string | null }): void;
    (event: 'open', projectId: number): void;
}>();

const form = reactive({ name: '', description: '' });
const submitting = ref(false);
const formError = ref<string | null>(null);

async function submit() {
    if (form.name.trim() === '') {
        formError.value = '專案名稱必填';

        return;
    }

    submitting.value = true;
    formError.value = null;
    try {
        emit('create', { name: form.name.trim(), description: form.description.trim() || null });
        form.name = '';
        form.description = '';
    } finally {
        submitting.value = false;
    }
}
</script>

<template>
    <section class="command-center">
        <header>
            <h2>專案</h2>
            <span class="count">{{ projects.length }}</span>
        </header>

        <form v-if="canCreate" class="new-project" @submit.prevent="submit">
            <input v-model="form.name" type="text" placeholder="專案名稱" aria-label="專案名稱" />
            <input v-model="form.description" type="text" placeholder="目標描述（CEO 會據此拆任務）" aria-label="目標描述" />
            <button type="submit" :disabled="submitting">建立</button>
            <p v-if="formError" class="error" role="alert">{{ formError }}</p>
        </form>

        <p v-if="loading" class="hint">載入中…</p>
        <p v-else-if="projects.length === 0" class="hint">還沒有專案。建立一個，CEO Agent 會把它拆成任務。</p>

        <ul v-else class="project-list">
            <li v-for="project in projects" :key="project.id">
                <button type="button" class="project" @click="emit('open', project.id)">
                    <span class="name">{{ project.name }}</span>
                    <span class="status" :data-status="project.status">
                        {{ PROJECT_STATUS_LABELS[project.status] ?? project.status }}
                    </span>
                    <span v-if="project.task_count !== undefined" class="tasks">{{ project.task_count }} 個任務</span>
                </button>
            </li>
        </ul>
    </section>
</template>

<style scoped>
.command-center header {
    display: flex;
    align-items: baseline;
    gap: 0.5rem;
}

h2 {
    margin: 0 0 0.5rem;
    font-size: 1.1rem;
}

.count {
    color: var(--ai-muted);
    font-size: 0.85rem;
}

.new-project {
    display: flex;
    flex-wrap: wrap;
    gap: 0.5rem;
    margin-bottom: 0.75rem;
}

.new-project input {
    flex: 1 1 12rem;
    padding: 0.45rem 0.6rem;
    background: var(--ai-surface);
    border: 1px solid var(--ai-border);
    border-radius: 0.375rem;
    color: inherit;
}

.new-project button {
    padding: 0.45rem 1rem;
    border: 1px solid var(--ai-border);
    border-radius: 0.375rem;
    background: #2f855a;
    color: #fff;
    cursor: pointer;
}

.project-list {
    margin: 0;
    padding: 0;
    list-style: none;
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
}

.project {
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

.name {
    flex: 1;
    font-weight: 600;
}

.status {
    font-size: 0.75rem;
    padding: 0.15rem 0.5rem;
    border-radius: 999px;
    border: 1px solid var(--ai-border);
}

.status[data-status='active'] {
    color: #7fd18f;
}

.status[data-status='failed'] {
    color: #f2777a;
}

.tasks,
.hint {
    color: var(--ai-muted);
    font-size: 0.8rem;
}

.error {
    color: #f2777a;
    font-size: 0.8rem;
    width: 100%;
    margin: 0;
}
</style>
