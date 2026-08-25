import client from '@/api/client';
import type { ApiSuccess } from '@/types';
import type { AiOfficeTask, TaskStatus } from '../types';

export async function listTasks(projectId: number): Promise<AiOfficeTask[]> {
    const response = await client.get<ApiSuccess<AiOfficeTask[]>>(
        `/ai-office/projects/${projectId}/tasks`,
        { params: { per_page: 100 } },
    );

    return response.data.data;
}

export async function getTask(id: number): Promise<AiOfficeTask> {
    const response = await client.get<ApiSuccess<AiOfficeTask>>(`/ai-office/tasks/${id}`);

    return response.data.data;
}

export interface NewTask {
    title: string;
    description?: string | null;
    priority?: number;
    assigned_agent_id?: number | null;
    dependencies?: number[];
}

export async function createTask(projectId: number, payload: NewTask): Promise<AiOfficeTask> {
    const response = await client.post<ApiSuccess<AiOfficeTask>>(
        `/ai-office/projects/${projectId}/tasks`,
        payload,
    );

    return response.data.data;
}

export async function updateTask(
    id: number,
    payload: Partial<{ status: TaskStatus; priority: number; assigned_agent_id: number | null }>,
): Promise<AiOfficeTask> {
    const response = await client.patch<ApiSuccess<AiOfficeTask>>(`/ai-office/tasks/${id}`, payload);

    return response.data.data;
}
