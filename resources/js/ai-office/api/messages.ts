import client from '@/api/client';
import type { ApiSuccess } from '@/types';
import type { AiOfficeMessage } from '../types';

/** 規格第 34 節的 Agent 往來訊息（唯讀）。 */
export async function listMessages(projectId: number, afterId = 0): Promise<AiOfficeMessage[]> {
    const response = await client.get<ApiSuccess<AiOfficeMessage[]>>(
        `/ai-office/projects/${projectId}/messages`,
        { params: afterId > 0 ? { after_id: afterId } : {} },
    );

    return response.data.data;
}
