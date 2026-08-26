import client from '@/api/client';
import type { ApiSuccess } from '@/types';
import type { AiOfficeDashboard } from '../types';

/**
 * 規格第 38／50 節的 `GET /dashboard`。統計必須由後端算——前端從分頁清單自己數的
 * 數字會隨著「載入了幾頁」變動，比 hardcode 更難發現是錯的。
 */
export async function fetchDashboard(): Promise<AiOfficeDashboard> {
    const response = await client.get<ApiSuccess<AiOfficeDashboard>>('/ai-office/dashboard');

    return response.data.data;
}
