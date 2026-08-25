import { defineStore } from 'pinia';
import { getAgent, listAgents, listMemories } from '../api/agents';
import type { AgentMemoryItem, AiOfficeAgent } from '../types';

interface State {
    agents: AiOfficeAgent[];
    detail: AiOfficeAgent | null;
    memories: AgentMemoryItem[];
    /** 清單前這麼多則才會真的進下次的 prompt（後端 config 決定）。 */
    recallLimit: number;
    loading: boolean;
    error: string | null;
}

export const useAgentsStore = defineStore('aiOfficeAgents', {
    state: (): State => ({ agents: [], detail: null, memories: [], recallLimit: 0, loading: false, error: null }),
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
            // 詳情與記憶一起抓：分開等的話，面板會先出現一半的資訊。
            const [detail, memories] = await Promise.all([getAgent(id), listMemories(id)]);

            this.detail = detail;
            this.memories = memories.memories;
            this.recallLimit = memories.recallLimit;
        },
        /** 事件流說某個 Agent 換狀態時，只改那一筆，不整份重抓。 */
        applyStatus(agentId: number, status: AiOfficeAgent['status']) {
            this.agents = this.agents.map((agent) => (agent.id === agentId ? { ...agent, status } : agent));
        },
    },
});
