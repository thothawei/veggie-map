<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue';
import AiOfficeShell from '../components/AiOfficeShell.vue';
import { useProjectsStore } from '../stores/projects';
import { fetchLogs } from '../api/logs';
import { formatEventTime } from '../labels';
import type { AiOfficeActivity } from '../types';

const projects = useProjectsStore();

const filters = reactive<{ project_id: number | null; type: string }>({
    project_id: null,
    type: '',
});

const entries = ref<AiOfficeActivity[]>([]);
const currentPage = ref(1);
const lastPage = ref(1);
const total = ref(0);
const loading = ref(false);
const error = ref<string | null>(null);

async function load(page = 1) {
    loading.value = true;
    error.value = null;
    try {
        const result = await fetchLogs({
            project_id: filters.project_id,
            type: filters.type || null,
            page,
        });
        entries.value = result.entries;
        currentPage.value = result.currentPage;
        lastPage.value = result.lastPage;
        total.value = result.total;
    } catch {
        error.value = '載入紀錄失敗';
        entries.value = [];
    } finally {
        loading.value = false;
    }
}

function apply() {
    void load(1);
}

function goToPage(page: number) {
    if (page < 1 || page > lastPage.value) return;
    void load(page);
}

onMounted(() => {
    void projects.fetchAll();
    void load();
});
</script>

<template>
    <AiOfficeShell title="執行紀錄">
        <!--
          規格第 44 節 LogsView：跨專案的事件紀錄。粒度選 activities（事件摘要），
          不是 tool_executions／task_runs 那種更細的單次執行紀錄——三者塞進同一頁
          只會變成沒人看的瀑布流，見後端 ActivityController::across() 的說明。
        -->
        <form class="filters" @submit.prevent="apply">
            <label>
                專案
                <select v-model.number="filters.project_id">
                    <option :value="null">全部</option>
                    <option v-for="project in projects.projects" :key="project.id" :value="project.id">
                        {{ project.name }}
                    </option>
                </select>
            </label>
            <label>
                事件類型
                <input v-model="filters.type" type="text" placeholder="例如 TaskCompleted" />
            </label>
            <button type="submit">套用</button>
        </form>

        <p v-if="error" class="error" role="alert">{{ error }}</p>
        <p v-else-if="loading" class="hint" aria-busy="true">載入中…</p>
        <p v-else-if="entries.length === 0" class="hint">沒有符合條件的紀錄。</p>

        <ol v-else class="log-list">
            <li v-for="entry in entries" :key="entry.id">
                <span class="time">{{ formatEventTime(entry.created_at) }}</span>
                <span class="project">{{ entry.project_name ?? '（未知專案）' }}</span>
                <span class="type">{{ entry.type }}</span>
                <span class="description">{{ entry.description }}</span>
            </li>
        </ol>

        <nav v-if="lastPage > 1" class="pagination" aria-label="分頁">
            <button type="button" :disabled="currentPage <= 1" @click="goToPage(currentPage - 1)">上一頁</button>
            <span>第 {{ currentPage }} / {{ lastPage }} 頁（共 {{ total }} 筆）</span>
            <button type="button" :disabled="currentPage >= lastPage" @click="goToPage(currentPage + 1)">下一頁</button>
        </nav>
    </AiOfficeShell>
</template>

<style scoped>
.filters {
    display: flex;
    flex-wrap: wrap;
    align-items: flex-end;
    gap: 0.75rem;
    margin-bottom: 0.75rem;
}

.filters label {
    display: flex;
    flex-direction: column;
    gap: 0.2rem;
    font-size: 0.75rem;
    color: var(--ai-muted);
}

.filters select,
.filters input {
    padding: 0.35rem 0.5rem;
    background: var(--ai-surface);
    border: 1px solid var(--ai-border);
    border-radius: 0.375rem;
    color: inherit;
}

.filters button,
.pagination button {
    padding: 0.4rem 1rem;
    background: #2f855a;
    border: 1px solid var(--ai-border);
    border-radius: 0.375rem;
    color: #fff;
    cursor: pointer;
}

.pagination button:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

.log-list {
    margin: 0;
    padding: 0;
    list-style: none;
    display: flex;
    flex-direction: column;
    gap: 0.35rem;
}

.log-list li {
    display: grid;
    grid-template-columns: 9rem 8rem 9rem 1fr;
    gap: 0.5rem;
    padding: 0.35rem 0.5rem;
    background: var(--ai-surface);
    border-radius: 0.375rem;
    font-size: 0.85rem;
}

@media (max-width: 720px) {
    .log-list li {
        grid-template-columns: 1fr;
        gap: 0.15rem;
    }
}

.time,
.project,
.type {
    color: var(--ai-muted);
    font-variant-numeric: tabular-nums;
}

.pagination {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    margin-top: 0.75rem;
    font-size: 0.8rem;
}

.error {
    color: #f2777a;
    font-size: 0.85rem;
}

.hint {
    color: var(--ai-muted);
    font-size: 0.85rem;
}
</style>
