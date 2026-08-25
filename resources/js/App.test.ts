import { beforeEach, describe, expect, it, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { createMemoryHistory, createRouter, type Router } from 'vue-router';
import { useAuthStore } from '@/stores/auth';

vi.mock('@/api/client', () => ({ default: { get: vi.fn(), post: vi.fn() } }));

const App = (await import('./App.vue')).default;

const stub = { template: '<div />' };

function makeRouter(): Router {
    return createRouter({
        history: createMemoryHistory(),
        routes: [
            { path: '/', name: 'home', component: stub },
            { path: '/restaurants', name: 'restaurants', component: stub },
            { path: '/login', name: 'login', component: stub },
            { path: '/favorites', name: 'favorites', component: stub },
            { path: '/profile', name: 'profile', component: stub },
            { path: '/admin', name: 'admin', component: stub },
        ],
    });
}

async function mountApp() {
    const router = makeRouter();
    await router.push('/');
    await router.isReady();

    return mount(App, { global: { plugins: [router] } });
}

function navLinks(wrapper: Awaited<ReturnType<typeof mountApp>>): string[] {
    return wrapper.findAll('.app-header nav a, .app-header nav button').map((el) => el.text());
}

describe('App 導覽列', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        localStorage.clear();
    });

    it('匿名者看不到任何登入或會員入口', async () => {
        // 2026-08-25 決定：消費者端不需要帳號，瀏覽／搜尋／看詳細資料全部免登入。
        // 導覽列出現「登入」「收藏」「個人資料」就等於把人推去註冊。
        const links = navLinks(await mountApp());

        expect(links).toContain('餐廳搜尋');
        expect(links).not.toContain('登入');
        expect(links).not.toContain('收藏');
        expect(links).not.toContain('個人資料');
        expect(links).not.toContain('登出');
    });

    it('管理員仍看得到後台與登出——那不是消費者功能', async () => {
        // 後台審核與 AI Office 子系統還是要登入，所以這兩個入口必須留著。
        const auth = useAuthStore();
        auth.token = 'fake-token';
        auth.user = { id: 1, name: '管理員', email: 'a@b.c', role: 'admin' } as never;

        const links = navLinks(await mountApp());

        expect(links).toContain('管理後台');
        expect(links).toContain('登出');
    });

    it('登入的一般使用者不會看到會員入口，但可以登出', async () => {
        const auth = useAuthStore();
        auth.token = 'fake-token';
        auth.user = { id: 2, name: '使用者', email: 'u@b.c', role: 'user' } as never;

        const links = navLinks(await mountApp());

        expect(links).not.toContain('收藏');
        expect(links).not.toContain('個人資料');
        expect(links).not.toContain('管理後台');
        expect(links).toContain('登出');
    });
});
