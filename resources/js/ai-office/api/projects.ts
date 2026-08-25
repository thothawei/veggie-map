import client from '@/api/client';
import type { ApiSuccess } from '@/types';
import type { AiOfficeProject, ProjectStatus } from '../types';

export async function listProjects(status?: ProjectStatus | 'all'): Promise<AiOfficeProject[]> {
    const response = await client.get<ApiSuccess<AiOfficeProject[]>>('/ai-office/projects', {
        params: status && status !== 'all' ? { status } : undefined,
    });

    return response.data.data;
}

export async function getProject(id: number): Promise<AiOfficeProject> {
    const response = await client.get<ApiSuccess<AiOfficeProject>>(`/ai-office/projects/${id}`);

    return response.data.data;
}

export interface NewProject {
    name: string;
    description?: string | null;
    repository_url?: string | null;
}

/**
 * 201 回來時專案已建立，但規劃還在佇列裡跑（HTTP 內不呼叫 LLM，見 docs/api.md）。
 * 所以呼叫端拿到的 status 會是 planning，任務要等事件流才會出現。
 */
export async function createProject(payload: NewProject): Promise<AiOfficeProject> {
    const response = await client.post<ApiSuccess<AiOfficeProject>>('/ai-office/projects', payload);

    return response.data.data;
}
