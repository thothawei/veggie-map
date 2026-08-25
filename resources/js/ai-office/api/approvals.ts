import client from '@/api/client';
import type { ApiSuccess } from '@/types';
import type { AiOfficeApproval } from '../types';

export async function listApprovals(status: string = 'pending'): Promise<AiOfficeApproval[]> {
    const response = await client.get<ApiSuccess<AiOfficeApproval[]>>('/ai-office/approvals', {
        params: { status },
    });

    return response.data.data;
}

export async function approve(id: number, comment?: string): Promise<AiOfficeApproval> {
    const response = await client.post<ApiSuccess<AiOfficeApproval>>(
        `/ai-office/approvals/${id}/approve`,
        comment ? { comment } : {},
    );

    return response.data.data;
}

export async function reject(id: number, comment?: string): Promise<AiOfficeApproval> {
    const response = await client.post<ApiSuccess<AiOfficeApproval>>(
        `/ai-office/approvals/${id}/reject`,
        comment ? { comment } : {},
    );

    return response.data.data;
}
