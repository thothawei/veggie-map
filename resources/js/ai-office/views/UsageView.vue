<script setup lang="ts">
import { computed, onMounted, reactive } from 'vue';
import AiOfficeShell from '../components/AiOfficeShell.vue';
import StatisticsPanel from '../components/dashboard/StatisticsPanel.vue';
import DailyUsageChart from '../components/usage/DailyUsageChart.vue';
import AgentPerformanceTable from '../components/usage/AgentPerformanceTable.vue';
import { useProjectsStore } from '../stores/projects';
import { useUsageStore } from '../stores/usage';

const usage = useUsageStore();
const projects = useProjectsStore();

const filters = reactive<{ project_id: number | null; from: string; to: string }>({
    project_id: null,
    from: '',
    to: '',
});

const stats = computed(() => [
    { label: 'LLM 請求數', value: usage.totals.requests },
    { label: '輸入 tokens', value: usage.totals.input_tokens.toLocaleString() },
    { label: '輸出 tokens', value: usage.totals.output_tokens.toLocaleString() },
    { label: '估計成本 (USD)', value: usage.totals.estimated_cost },
]);

/** 目前這份估價是哪來的。沒有這行，畫面上的金額就是一個沒有來源的數字。 */
const pricedModels = computed(() => Object.entries(usage.pricing)
    .map(([model, price]) => `${model} $${price.input}/$${price.output}`)
    .join('、'));

function apply() {
    void usage.fetch({
        project_id: filters.project_id,
        from: filters.from || null,
        to: filters.to || null,
    });
}

onMounted(() => {
    void projects.fetchAll();
    apply();
});
</script>

<template>
    <AiOfficeShell title="用量與成本">
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
                起
                <input v-model="filters.from" type="date" />
            </label>
            <label>
                迄
                <input v-model="filters.to" type="date" />
            </label>
            <button type="submit">套用</button>
        </form>

        <p v-if="usage.error" class="error" role="alert">{{ usage.error }}</p>
        <p v-else-if="usage.loading" class="hint">載入中…</p>

        <StatisticsPanel :items="stats" />

        <p class="pricing-note">
            成本是用 <code>config/ai_office.php</code> 的價目表估的（每百萬 token）：{{ pricedModels || '尚未設定' }}。
            清單上沒有的模型一律估成 0，不會憑空補一個單價。
        </p>

        <section class="panel block">
            <h2>每日 token 用量</h2>
            <DailyUsageChart :rows="usage.daily" />
        </section>

        <div class="grid">
            <section class="panel">
                <h2>依模型</h2>
                <ul class="rows">
                    <li v-for="row in usage.byModel" :key="row.model">
                        <span>{{ row.model }}</span>
                        <span>{{ row.total_tokens.toLocaleString() }} tokens</span>
                        <span>${{ row.estimated_cost }}</span>
                    </li>
                    <li v-if="usage.byModel.length === 0" class="empty">沒有資料。</li>
                </ul>
            </section>

            <section class="panel">
                <h2>依專案</h2>
                <ul class="rows">
                    <li v-for="row in usage.byProject" :key="row.project_id ?? 'none'">
                        <span>{{ row.project_name ?? '（未指定專案）' }}</span>
                        <span>{{ row.total_tokens.toLocaleString() }} tokens</span>
                        <span>${{ row.estimated_cost }}</span>
                    </li>
                    <li v-if="usage.byProject.length === 0" class="empty">沒有資料。</li>
                </ul>
            </section>
        </div>

        <section class="panel block">
            <h2>Agent 效能</h2>
            <AgentPerformanceTable :rows="usage.performance" />
        </section>
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

.filters button {
    padding: 0.4rem 1rem;
    background: #2f855a;
    border: 1px solid var(--ai-border);
    border-radius: 0.375rem;
    color: #fff;
    cursor: pointer;
}

h2 {
    margin: 0 0 0.5rem;
    font-size: 1rem;
}

.block,
.grid {
    margin-top: 0.75rem;
}

.grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(18rem, 1fr));
    gap: 0.75rem;
}

.rows {
    margin: 0;
    padding: 0;
    list-style: none;
    font-size: 0.8rem;
}

.rows li {
    display: flex;
    justify-content: space-between;
    gap: 0.5rem;
    padding: 0.3rem 0;
    border-bottom: 1px solid var(--ai-border);
    font-variant-numeric: tabular-nums;
}

.pricing-note {
    margin: 0.5rem 0 0;
    font-size: 0.75rem;
    color: var(--ai-muted);
}

.error {
    color: #f2777a;
    font-size: 0.85rem;
}

.hint,
.empty {
    color: var(--ai-muted);
    font-size: 0.8rem;
}
</style>
