import client from '@/api/client';
import type { ApiSuccess } from '@/types';
import type { AiOfficeAgent } from '../types';

export async function listAgents(): Promise<AiOfficeAgent[]> {
    const response = await client.get<ApiSuccess<AiOfficeAgent[]>>('/ai-office/agents');

    return response.data.data;
}

/** 詳細模式才有 system prompt／工具／權限表。 */
export async function getAgent(id: number): Promise<AiOfficeAgent> {
    const response = await client.get<ApiSuccess<AiOfficeAgent>>(`/ai-office/agents/${id}`);

    return response.data.data;
}
