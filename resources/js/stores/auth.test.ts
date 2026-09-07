import { beforeEach, describe, expect, it, vi } from 'vitest';
import { createPinia, setActivePinia } from 'pinia';

const get = vi.fn();
const post = vi.fn();

vi.mock('@/api/client', () => ({
    default: { get: (...a: unknown[]) => get(...a), post: (...a: unknown[]) => post(...a) },
}));

const { useAuthStore } = await import('./auth');

describe('useAuthStore', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        localStorage.clear();
        get.mockReset();
        post.mockReset();
    });

    it('login 把 token 與過期時間存進 localStorage', async () => {
        post.mockResolvedValue({
            data: { data: { token: 'tok-1', expires_at: '2026-09-10T00:00:00Z', user: { id: 1, name: 'A', email: 'a@b.c', role: 'user' } } },
        });

        const auth = useAuthStore();
        await auth.login('a@b.c', 'password');

        expect(auth.token).toBe('tok-1');
        expect(auth.expiresAt).toBe('2026-09-10T00:00:00Z');
        expect(localStorage.getItem('veggiemap_token')).toBe('tok-1');
        expect(localStorage.getItem('veggiemap_token_expires_at')).toBe('2026-09-10T00:00:00Z');
    });

    it('過期時間是 null 時（永不過期）不寫進 localStorage', async () => {
        post.mockResolvedValue({
            data: { data: { token: 'tok-2', expires_at: null, user: { id: 1, name: 'A', email: 'a@b.c', role: 'user' } } },
        });

        const auth = useAuthStore();
        await auth.login('a@b.c', 'password');

        expect(auth.expiresAt).toBeNull();
        expect(localStorage.getItem('veggiemap_token_expires_at')).toBeNull();
    });

    it('refresh 換掉 token 與過期時間', async () => {
        const auth = useAuthStore();
        auth.setToken('old-token', '2026-09-06T13:00:00Z');

        post.mockResolvedValue({ data: { data: { token: 'new-token', expires_at: '2026-09-13T13:00:00Z' } } });

        await auth.refresh();

        expect(post).toHaveBeenCalledWith('/auth/refresh');
        expect(auth.token).toBe('new-token');
        expect(auth.expiresAt).toBe('2026-09-13T13:00:00Z');
    });

    /**
     * clearSession 給「token 已經失效」的情境用（401 攔截器、refresh 失敗）——
     * 這種情況打 /auth/logout 只會再拿到一次 401，沒有意義，所以不該呼叫它。
     */
    it('clearSession 只清本機狀態，不打 /auth/logout', () => {
        const auth = useAuthStore();
        auth.setToken('tok', '2026-09-10T00:00:00Z');
        auth.user = { id: 1, name: 'A', email: 'a@b.c', role: 'user', created_at: '' };

        auth.clearSession();

        expect(auth.token).toBeNull();
        expect(auth.expiresAt).toBeNull();
        expect(auth.user).toBeNull();
        expect(post).not.toHaveBeenCalled();
    });

    it('logout 打 /auth/logout 且失敗時仍然清掉本機狀態（finally 保底）', async () => {
        post.mockRejectedValue(new Error('network'));

        const auth = useAuthStore();
        auth.setToken('tok', '2026-09-10T00:00:00Z');

        await expect(auth.logout()).rejects.toThrow('network');

        expect(auth.token).toBeNull();
        expect(auth.isAuthenticated).toBe(false);
    });
});
