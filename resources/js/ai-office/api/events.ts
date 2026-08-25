import client from '@/api/client';
import type { ApiSuccess } from '@/types';
import type { AiOfficeActivity, StreamTicket } from '../types';

/**
 * 補漏用：帶 `after_id` 時後端回的是「比它新、由舊到新」的事件，
 * 剛好可以直接接在既有清單後面（見 docs/api.md 的事件流段落）。
 */
export async function listActivities(
    projectId: number,
    afterId?: number,
): Promise<{ activities: AiOfficeActivity[]; latestId: number }> {
    const response = await client.get<ApiSuccess<AiOfficeActivity[]>>(
        `/ai-office/projects/${projectId}/activities`,
        { params: { per_page: 50, ...(afterId ? { after_id: afterId } : {}) } },
    );

    return {
        activities: response.data.data,
        latestId: Number(response.data.meta?.latest_id ?? 0),
    };
}

/** EventSource 帶不了 Authorization 標頭，所以先用 Bearer token 換一張一次性票。 */
export async function requestTicket(projectId: number): Promise<StreamTicket> {
    const response = await client.post<ApiSuccess<StreamTicket>>(
        `/ai-office/projects/${projectId}/events/ticket`,
    );

    return response.data.data;
}

export function streamUrl(projectId: number, ticket: string, afterId: number): string {
    const base = import.meta.env.VITE_API_BASE_URL ?? '';
    const params = new URLSearchParams({ ticket, after_id: String(afterId) });

    return `${base}/api/v1/ai-office/projects/${projectId}/events?${params.toString()}`;
}
