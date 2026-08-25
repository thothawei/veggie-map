<script setup lang="ts">
import { AGENT_STATUS_LABELS } from '../../labels';
import type { AgentPerformance } from '../../types';

defineProps<{ rows: AgentPerformance[] }>();

/** 沒接過任務時後端回 null，這裡就寫「—」，不要顯示 0%——那是兩件不同的事。 */
function percent(value: number | null): string {
    return value === null ? '—' : `${Math.round(value * 100)}%`;
}

function duration(ms: number | null): string {
    if (ms === null) {
        return '—';
    }

    return ms < 1000 ? `${ms} ms` : `${(ms / 1000).toFixed(1)} 秒`;
}
</script>

<template>
    <table class="performance">
        <thead>
            <tr>
                <th scope="col">Agent</th>
                <th scope="col">狀態</th>
                <th scope="col">任務</th>
                <th scope="col">完成</th>
                <th scope="col">失敗</th>
                <th scope="col">成功率</th>
                <th scope="col">平均耗時</th>
                <th scope="col">重試</th>
                <th scope="col">Tokens</th>
                <th scope="col">成本 (USD)</th>
            </tr>
        </thead>
        <tbody>
            <tr v-for="row in rows" :key="row.agent_id">
                <th scope="row">{{ row.name }} <span class="role">{{ row.role }}</span></th>
                <td>{{ AGENT_STATUS_LABELS[row.status] ?? row.status }}</td>
                <td>{{ row.tasks }}</td>
                <td>{{ row.completed }}</td>
                <td :class="{ bad: row.failed > 0 }">{{ row.failed }}</td>
                <td>{{ percent(row.success_rate) }}</td>
                <td>{{ duration(row.avg_duration_ms) }}</td>
                <td>{{ row.retries }}</td>
                <td>{{ row.total_tokens.toLocaleString() }}</td>
                <td>{{ row.estimated_cost }}</td>
            </tr>
            <tr v-if="rows.length === 0">
                <td colspan="10" class="empty">還沒有 Agent。</td>
            </tr>
        </tbody>
    </table>
</template>

<style scoped>
.performance {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.8rem;
}

th,
td {
    padding: 0.35rem 0.5rem;
    text-align: right;
    border-bottom: 1px solid var(--ai-border);
    font-variant-numeric: tabular-nums;
}

thead th {
    color: var(--ai-muted);
    font-weight: 500;
}

tbody th[scope='row'] {
    text-align: left;
    font-weight: 600;
}

.role {
    color: var(--ai-muted);
    font-weight: 400;
    font-size: 0.7rem;
}

.bad {
    color: #f2777a;
}

.empty {
    text-align: center;
    color: var(--ai-muted);
}
</style>
