import client from '@/api/client';
import type { ApiSuccess } from '@/types';
import type { ResourceUsageSnapshot } from '../types';

/** 規格第 39、44 節：ResourceUsage。應用層指標，不是真的 host CPU/Memory。 */
export async function fetchResourceUsage(): Promise<ResourceUsageSnapshot> {
    const response = await client.get<ApiSuccess<ResourceUsageSnapshot>>('/ai-office/resource-usage');

    return response.data.data;
}
