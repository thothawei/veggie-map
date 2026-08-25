<script setup lang="ts">
import { computed } from 'vue';
import { TASK_STATUS_LABELS, formatEventTime } from '../../labels';
import type { AiOfficeTask } from '../../types';

const props = defineProps<{ task: AiOfficeTask; agentName?: string | null; canEdit?: boolean }>();

const emit = defineEmits<{
    (event: 'close'): void;
    (event: 'cancel', taskId: number): void;
}>();

/** result 是 Agent 回填的自由結構，直接印 JSON 比假裝知道欄位長什麼樣誠實。 */
const resultText = computed(() => (props.task.result ? JSON.stringify(props.task.result, null, 2) : null));

/** 只有還沒進終點的任務可以取消，避免對已完成的任務送出必定 422 的請求。 */
const cancellable = computed(() => ['pending', 'planning', 'assigned', 'waiting_review'].includes(props.task.status));
</script>

<template>
    <aside class="task-detail">
        <header>
            <h2>{{ task.title }}</h2>
            <button type="button" class="close" aria-label="關閉任務詳情" @click="emit('close')">×</button>
        </header>

        <dl>
            <div>
                <dt>狀態</dt>
                <dd>{{ TASK_STATUS_LABELS[task.status] ?? task.status }}</dd>
            </div>
            <div>
                <dt>負責 Agent</dt>
                <dd>{{ agentName ?? '未指派' }}</dd>
            </div>
            <div>
                <dt>優先度</dt>
                <dd>{{ task.priority }}</dd>
            </div>
            <div>
                <dt>重試</dt>
                <dd>{{ task.retry_count }} / {{ task.max_retries }}</dd>
            </div>
            <div v-if="task.dependencies?.length">
                <dt>前置任務</dt>
                <dd>#{{ task.dependencies.join('、#') }}</dd>
            </div>
            <div v-if="task.dependencies_satisfied !== undefined">
                <dt>前置是否完成</dt>
                <dd>{{ task.dependencies_satisfied ? '是' : '否' }}</dd>
            </div>
            <div v-if="task.started_at">
                <dt>開始</dt>
                <dd>{{ formatEventTime(task.started_at) }}</dd>
            </div>
            <div v-if="task.completed_at">
                <dt>結束</dt>
                <dd>{{ formatEventTime(task.completed_at) }}</dd>
            </div>
        </dl>

        <p v-if="task.description" class="description">{{ task.description }}</p>
        <p v-if="task.error" class="error" role="alert">{{ task.error }}</p>
        <pre v-if="resultText" class="result">{{ resultText }}</pre>

        <button v-if="canEdit && cancellable" type="button" class="cancel" @click="emit('cancel', task.id)">
            取消這個任務
        </button>
    </aside>
</template>

<style scoped>
.task-detail {
    padding: 0.75rem;
    background: var(--ai-surface);
    border: 1px solid var(--ai-border);
    border-radius: 0.5rem;
}

header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 0.5rem;
}

h2 {
    margin: 0 0 0.5rem;
    font-size: 1rem;
}

.close {
    background: none;
    border: none;
    color: var(--ai-muted);
    font-size: 1.25rem;
    line-height: 1;
    cursor: pointer;
}

dl {
    margin: 0;
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(9rem, 1fr));
    gap: 0.5rem;
}

dt {
    font-size: 0.75rem;
    color: var(--ai-muted);
}

dd {
    margin: 0;
    font-size: 0.9rem;
}

.description {
    margin: 0.75rem 0 0;
    font-size: 0.85rem;
}

.error {
    margin: 0.5rem 0 0;
    color: #f2777a;
    font-size: 0.85rem;
    white-space: pre-wrap;
}

.result {
    margin: 0.5rem 0 0;
    padding: 0.5rem;
    background: var(--ai-bg);
    border-radius: 0.375rem;
    font-size: 0.75rem;
    overflow-x: auto;
}

.cancel {
    margin-top: 0.75rem;
    padding: 0.35rem 0.9rem;
    background: transparent;
    border: 1px solid var(--ai-border);
    border-radius: 0.375rem;
    color: #f2777a;
    cursor: pointer;
}
</style>
