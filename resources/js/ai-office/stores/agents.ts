import { defineStore } from 'pinia';
import { getAgent, listAgents } from '../api/agents';
import type { AiOfficeAgent } from '../types';

interface State {
    agents: AiOfficeAgent[];
    detail: AiOfficeAgent | null;
    loading: boolean;
    error: string | null;
}

export const useAgentsStore = defineStore('aiOfficeAgents', {
    state: (): State => ({ agents: [], detail: null, loading: false, error: null }),
    getters: {
        busyCount: (state) => state.agents.filter((agent) => agent.status === 'working').length,
    },
    actions: {
        async fetchAll() {
            this.loading = true;
            this.error = null;
            try {
                this.agents = await listAgents();
            } catch {
                this.error = '載入 Agent 失敗';
            } finally {
                this.loading = false;
            }
        },
        async fetchDetail(id: number) {
            this.detail = await getAgent(id);
        },
        /** 事件流說某個 Agent 換狀態時，只改那一筆，不整份重抓。 */
        applyStatus(agentId: number, status: AiOfficeAgent['status']) {
            this.agents = this.agents.map((agent) => (agent.id === agentId ? { ...agent, status } : agent));
        },
    },
});
