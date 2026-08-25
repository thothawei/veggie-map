import client from '@/api/client';
import type { ApiSuccess } from '@/types';
import type { AgentPerformance, UsageReport } from '../types';

export interface UsageFilters {
    project_id?: number | null;
    agent_id?: number | null;
    from?: string | null;
    to?: string | null;
}

/** 空字串的日期會被後端的 `date` 規則擋成 422，送出前先清掉。 */
function clean(filters: UsageFilters): Record<string, string | number> {
    return Object.fromEntries(
        Object.entries(filters).filter(([, value]) => value !== null && value !== undefined && value !== ''),
    ) as Record<string, string | number>;
}

export async function getUsage(filters: UsageFilters = {}): Promise<{ report: UsageReport; pricing: Record<string, { input: number; output: number }> }> {
    const response = await client.get<ApiSuccess<UsageReport>>('/ai-office/usage', { params: clean(filters) });

    return {
        report: response.data.data,
        pricing: (response.data.meta?.pricing ?? {}) as Record<string, { input: number; output: number }>,
    };
}

export async function getAgentPerformance(projectId?: number | null): Promise<AgentPerformance[]> {
    const response = await client.get<ApiSuccess<AgentPerformance[]>>('/ai-office/stats/agents', {
        params: projectId ? { project_id: projectId } : undefined,
    });

    return response.data.data;
}
