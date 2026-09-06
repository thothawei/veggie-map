import client from '@/api/client';
import type { ApiSuccess } from '@/types';
import type { AiOfficeActivity } from '../types';

export interface LogsFilters {
    project_id?: number | null;
    agent_id?: number | null;
    task_id?: number | null;
    type?: string | null;
    per_page?: number;
    page?: number;
}

export interface LogsPage {
    entries: AiOfficeActivity[];
    currentPage: number;
    lastPage: number;
    total: number;
}

/** 空字串／null 的篩選欄位會被後端的 `nullable` 規則接受，但送出前先清掉比較乾淨。 */
function clean(filters: LogsFilters): Record<string, string | number> {
    return Object.fromEntries(
        Object.entries(filters).filter(([, value]) => value !== null && value !== undefined && value !== ''),
    ) as Record<string, string | number>;
}

/** 規格第 44 節 `LogsView`：跨專案的執行紀錄，見後端 ActivityController::across()。 */
export async function fetchLogs(filters: LogsFilters = {}): Promise<LogsPage> {
    const response = await client.get<ApiSuccess<AiOfficeActivity[]>>('/ai-office/activities', {
        params: clean(filters),
    });

    return {
        entries: response.data.data,
        currentPage: Number(response.data.meta?.current_page ?? 1),
        lastPage: Number(response.data.meta?.last_page ?? 1),
        total: Number(response.data.meta?.total ?? response.data.data.length),
    };
}
