import client from '@/api/client';
import type { ApiSuccess } from '@/types';
import type { AgentMemoryItem, AiOfficeAgent } from '../types';

export async function listAgents(): Promise<AiOfficeAgent[]> {
    const response = await client.get<ApiSuccess<AiOfficeAgent[]>>('/ai-office/agents');

    return response.data.data;
}

/** 詳細模式才有 system prompt／工具／權限表。 */
export async function getAgent(id: number): Promise<AiOfficeAgent> {
    const response = await client.get<ApiSuccess<AiOfficeAgent>>(`/ai-office/agents/${id}`);

    return response.data.data;
}

/**
 * 這個 Agent 記得的事（規格第 41 節）。排序跟後端 recall 一致，所以清單前
 * `recall_limit` 則就是下次執行真的會被放進 prompt 的那幾則。
 */
export async function listMemories(
    agentId: number,
): Promise<{ memories: AgentMemoryItem[]; recallLimit: number }> {
    const response = await client.get<ApiSuccess<AgentMemoryItem[]>>(`/ai-office/agents/${agentId}/memories`);

    return {
        memories: response.data.data,
        recallLimit: Number(response.data.meta?.recall_limit ?? 0),
    };
}
