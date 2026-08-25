import { beforeEach, describe, expect, it, vi } from 'vitest';
import { createPinia, setActivePinia } from 'pinia';

vi.mock('@/api/client', () => ({ default: { get: vi.fn(), post: vi.fn(), patch: vi.fn() } }));

const { useAuthStore } = await import('@/stores/auth');
const router = (await import('./index')).default;

/**
 * 前端的守衛不是安全邊界（後端 `ai-office` 中介層才是），但少了它，
 * 一般消費者會看到一個載入後才整片 403 的畫面。
 */
describe('AI Office 路由守衛', () => {
    beforeEach(async () => {
        setActivePinia(createPinia());
        localStorage.setItem('veggiemap_token', 'token');
        await router.replace('/');
        await router.isReady();
    });

    async function goToAiOfficeAs(role: string | null) {
        const auth = useAuthStore();
        auth.setToken('token');
        auth.user = role === null
            ? null
            : { id: 1, name: '測試', email: 't@example.com', role: role as 'viewer', created_at: '' };

        await router.push('/ai-office');

        return router.currentRoute.value.name;
    }

    it('一般消費者角色 user 會被導回首頁', async () => {
        expect(await goToAiOfficeAs('user')).toBe('home');
    });

    it('viewer／developer／manager／admin 都進得去', async () => {
        for (const role of ['viewer', 'developer', 'manager', 'admin']) {
            expect(await goToAiOfficeAs(role)).toBe('ai-office');
            await router.replace('/');
        }
    });

    it('沒登入時導去登入頁並記住原本要去的路徑', async () => {
        const auth = useAuthStore();
        auth.setToken(null);
        auth.user = null;

        await router.push('/ai-office/approvals');

        expect(router.currentRoute.value.name).toBe('login');
        expect(router.currentRoute.value.query.redirect).toBe('/ai-office/approvals');
    });
});
