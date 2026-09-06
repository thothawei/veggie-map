<script setup lang="ts">
import { onMounted, ref } from 'vue';
import AiOfficeShell from '../components/AiOfficeShell.vue';
import AgentList from '../components/agent/AgentList.vue';
import { useAgentsStore } from '../stores/agents';
import { extractApiErrorMessage } from '@/lib/apiError';
import { MEMORY_TYPE_LABELS } from '../labels';

const agents = useAgentsStore();
const detailError = ref<string | null>(null);

async function open(agentId: number) {
    detailError.value = null;
    try {
        await agents.fetchDetail(agentId);
    } catch (error: unknown) {
        detailError.value = extractApiErrorMessage(error, '載入 Agent 詳情失敗');
    }
}

onMounted(() => void agents.fetchAll());
</script>

<template>
    <AiOfficeShell title="Agent 團隊">
        <p v-if="agents.error" class="error" role="alert">{{ agents.error }}</p>
        <AgentList :agents="agents.agents" :loading="agents.loading" @open="open" />

        <section v-if="agents.detail" class="panel detail">
            <h2>{{ agents.detail.name }}</h2>
            <p v-if="detailError" class="error" role="alert">{{ detailError }}</p>
            <p class="muted">{{ agents.detail.description }}</p>

            <h3>效能</h3>
            <ul class="stats">
                <li>
                    <span class="value">{{ agents.detail.performance?.success_rate == null ? '尚無資料' : `${Math.round(agents.detail.performance.success_rate * 100)}%` }}</span>
                    <span class="label">成功率</span>
                </li>
                <li>
                    <span class="value">{{ agents.detail.performance?.avg_duration_ms == null ? '尚無資料' : `${agents.detail.performance.avg_duration_ms} ms` }}</span>
                    <span class="label">平均耗時</span>
                </li>
                <li>
                    <span class="value">{{ (agents.detail.performance?.total_tokens ?? 0).toLocaleString() }}</span>
                    <span class="label">Token 用量</span>
                </li>
            </ul>

            <h3>目前任務</h3>
            <p v-if="!agents.detail.current_task" class="muted">目前手上沒有任務。</p>
            <p v-else class="current-task">{{ agents.detail.current_task.title }}</p>

            <h3>最近的任務</h3>
            <p v-if="!agents.detail.recent_tasks?.length" class="muted">還沒有任何任務紀錄。</p>
            <ul v-else class="recent-list">
                <li v-for="task in agents.detail.recent_tasks" :key="task.id">
                    <span class="status" :data-status="task.status">{{ task.status }}</span>
                    <span>{{ task.title }}</span>
                </li>
            </ul>

            <h3>最近的錯誤</h3>
            <p v-if="!agents.detail.recent_errors?.length" class="muted">還沒有任何錯誤紀錄。</p>
            <ul v-else class="recent-list errors">
                <li v-for="error in agents.detail.recent_errors" :key="error.id">
                    <span class="status" data-status="error">{{ error.type }}</span>
                    <span>{{ error.message }}</span>
                </li>
            </ul>

            <h3>可用工具</h3>
            <p v-if="!agents.detail.tools?.length" class="muted">沒有掛任何工具。</p>
            <ul v-else class="tools">
                <li v-for="tool in agents.detail.tools" :key="tool">{{ tool }}</li>
            </ul>

            <h3>權限</h3>
            <ul class="permissions">
                <li v-for="(effect, ability) in agents.detail.permissions ?? {}" :key="ability">
                    <span>{{ ability }}</span>
                    <span :data-effect="effect">{{ effect }}</span>
                </li>
            </ul>

            <h3>記得的事（前 {{ agents.recallLimit }} 則會進下次的 prompt）</h3>
            <p v-if="agents.memories.length === 0" class="muted">
                還沒有記憶。Agent 每完成或失敗一個任務就會記一則。
            </p>
            <ol v-else class="memories">
                <li v-for="(memory, index) in agents.memories" :key="memory.id" :class="{ recalled: index < agents.recallLimit }">
                    <span class="type">{{ MEMORY_TYPE_LABELS[memory.memory_type] ?? memory.memory_type }}</span>
                    <span class="importance">重要度 {{ memory.importance }}</span>
                    <span class="content">{{ memory.content }}</span>
                </li>
            </ol>

            <h3>System prompt</h3>
            <pre class="prompt">{{ agents.detail.system_prompt }}</pre>
        </section>
    </AiOfficeShell>
</template>

<style scoped>
.detail {
    margin-top: 0.75rem;
}

h2 {
    margin: 0 0 0.25rem;
    font-size: 1rem;
}

h3 {
    margin: 0.75rem 0 0.35rem;
    font-size: 0.85rem;
    color: var(--ai-muted);
}

.muted {
    color: var(--ai-muted);
    font-size: 0.85rem;
}

.stats {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(6rem, 1fr));
    gap: 0.5rem;
    margin: 0;
    padding: 0;
    list-style: none;
}

.stats li {
    display: flex;
    flex-direction: column;
    gap: 0.15rem;
}

.stats .value {
    font-weight: 700;
    font-size: 0.95rem;
}

.stats .label {
    font-size: 0.75rem;
    color: var(--ai-muted);
}

.current-task {
    font-size: 0.85rem;
}

.recent-list {
    margin: 0;
    padding: 0;
    list-style: none;
    display: flex;
    flex-direction: column;
    gap: 0.25rem;
    font-size: 0.8rem;
}

.recent-list li {
    display: flex;
    gap: 0.5rem;
    align-items: baseline;
}

.recent-list .status {
    color: var(--ai-muted);
    font-size: 0.7rem;
    text-transform: uppercase;
}

.recent-list.errors .status {
    color: #f2777a;
}

.tools,
.permissions {
    margin: 0;
    padding: 0;
    list-style: none;
    display: flex;
    flex-direction: column;
    gap: 0.2rem;
    font-size: 0.85rem;
}

.permissions li {
    display: flex;
    justify-content: space-between;
    gap: 1rem;
}

.permissions [data-effect='deny'] {
    color: #f2777a;
}

.permissions [data-effect='allow'] {
    color: #7fd18f;
}

.memories {
    margin: 0;
    padding: 0;
    list-style: none;
    display: flex;
    flex-direction: column;
    gap: 0.3rem;
    font-size: 0.8rem;
}

.memories li {
    display: grid;
    grid-template-columns: 6rem 5rem 1fr;
    gap: 0.5rem;
    padding: 0.3rem 0.4rem;
    background: var(--ai-bg);
    border-radius: 0.25rem;
    /* 會進 prompt 的那幾則要看得出來，否則「記得很多」跟「真的會用到」分不開。 */
    opacity: 0.55;
}

.memories li.recalled {
    opacity: 1;
    border-left: 2px solid #2f855a;
}

.memories .type,
.memories .importance {
    color: var(--ai-muted);
}

@media (max-width: 640px) {
    .memories li {
        grid-template-columns: 1fr;
        gap: 0.15rem;
    }
}

.prompt {
    margin: 0;
    padding: 0.5rem;
    background: var(--ai-bg);
    border-radius: 0.375rem;
    font-size: 0.75rem;
    white-space: pre-wrap;
}

.error {
    color: #f2777a;
    font-size: 0.85rem;
}
</style>
