import { isAxiosError } from 'axios';
import type { ApiError } from '@/types';

/**
 * 統一從 axios 錯誤裡挖出 docs/api.md 定義的 `{success:false, error:{code,message,fields}}`
 * 格式，catch 區塊不用每個地方各自重複同一串 optional chaining，也不用 `any`。
 */
export function extractApiErrorMessage(error: unknown, fallback: string): string {
    if (isAxiosError<ApiError>(error) && error.response?.data?.error?.message) {
        return error.response.data.error.message;
    }

    return fallback;
}

export function extractApiErrorFields(error: unknown): Record<string, string[]> | null {
    if (isAxiosError<ApiError>(error) && error.response?.data?.error?.fields) {
        return error.response.data.error.fields;
    }

    return null;
}
