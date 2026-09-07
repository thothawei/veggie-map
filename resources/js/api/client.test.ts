import { beforeEach, describe, expect, it, vi } from 'vitest';

const clearSession = vi.fn();
const push = vi.fn();
let currentRouteName: string | symbol | undefined = 'restaurants';

vi.mock('@/stores/auth', () => ({
    useAuthStore: () => ({ clearSession }),
}));

vi.mock('@/router', () => ({
    default: {
        get currentRoute() {
            return { value: { name: currentRouteName, fullPath: '/restaurants' } };
        },
        push,
    },
}));

const client = (await import('./client')).default;

/**
 * 直接呼叫攔截器的 rejected handler，不用真的打網路——axios 的
 * `interceptors.response.handlers` 是它自己文件化的內部結構，這裡只取第一個
 * （這個檔案只註冊一個 response 攔截器）。
 */
function rejectedHandler() {
    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    return (client.interceptors.response as any).handlers[0].rejected as (error: unknown) => Promise<never>;
}

function errorWithStatus(status: number, url: string) {
    return { response: { status }, config: { url } };
}

describe('api client 的 401 攔截器', () => {
    beforeEach(() => {
        clearSession.mockReset();
        push.mockReset();
        currentRouteName = 'restaurants';
    });

    it('一般端點的 401 清掉 session 並導去登入頁', async () => {
        await expect(rejectedHandler()(errorWithStatus(401, '/me'))).rejects.toBeDefined();

        expect(clearSession).toHaveBeenCalledOnce();
        expect(push).toHaveBeenCalledWith({ name: 'login', query: { redirect: '/restaurants' } });
    });

    it('/auth/refresh 的 401 也要清掉 session——那代表 token 早就失效了', async () => {
        await expect(rejectedHandler()(errorWithStatus(401, '/auth/refresh'))).rejects.toBeDefined();

        expect(clearSession).toHaveBeenCalledOnce();
    });

    it('/auth/login 的 401 不觸發（登入失敗走它自己的 422 錯誤處理）', async () => {
        await expect(rejectedHandler()(errorWithStatus(401, '/auth/login'))).rejects.toBeDefined();

        expect(clearSession).not.toHaveBeenCalled();
    });

    it('已經在登入頁時不重複導頁', async () => {
        currentRouteName = 'login';

        await expect(rejectedHandler()(errorWithStatus(401, '/me'))).rejects.toBeDefined();

        expect(clearSession).toHaveBeenCalledOnce();
        expect(push).not.toHaveBeenCalled();
    });

    it('非 401 的錯誤不觸發攔截器邏輯', async () => {
        await expect(rejectedHandler()(errorWithStatus(500, '/me'))).rejects.toBeDefined();

        expect(clearSession).not.toHaveBeenCalled();
        expect(push).not.toHaveBeenCalled();
    });
});
