import { defineStore } from 'pinia';
import { getAgentPerformance, getUsage, type UsageFilters } from '../api/usage';
import type { AgentPerformance, UsageReport } from '../types';

interface State {
    report: UsageReport | null;
    performance: AgentPerformance[];
    pricing: Record<string, { input: number; output: number }>;
    filters: UsageFilters;
    loading: boolean;
    error: string | null;
}

const EMPTY_REPORT: UsageReport = {
    totals: { requests: 0, input_tokens: 0, output_tokens: 0, total_tokens: 0, estimated_cost: '0.000000' },
    by_model: [],
    by_agent: [],
    by_project: [],
    daily: [],
};

export const useUsageStore = defineStore('aiOfficeUsage', {
    state: (): State => ({
        report: null,
        performance: [],
        pricing: {},
        filters: {},
        loading: false,
        error: null,
    }),
    getters: {
        /** 還沒載入時給一份全 0 的骨架，讓畫面不用到處寫 `?.`。 */
        totals: (state) => state.report?.totals ?? EMPTY_REPORT.totals,
        daily: (state) => state.report?.daily ?? [],
        byModel: (state) => state.report?.by_model ?? [],
        byAgent: (state) => state.report?.by_agent ?? [],
        byProject: (state) => state.report?.by_project ?? [],
    },
    actions: {
        async fetch(filters: UsageFilters = {}) {
            this.loading = true;
            this.error = null;
            this.filters = filters;
            try {
                // 兩支端點一起打：分開等會讓畫面先出現一半的數字，看起來像資料不一致。
                const [usage, performance] = await Promise.all([
                    getUsage(filters),
                    getAgentPerformance(filters.project_id ?? null),
                ]);

                this.report = usage.report;
                this.pricing = usage.pricing;
                this.performance = performance;
            } catch {
                this.error = '載入用量資料失敗';
            } finally {
                this.loading = false;
            }
        },
    },
});
