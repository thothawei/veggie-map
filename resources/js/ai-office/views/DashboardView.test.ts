import { beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import { createMemoryHistory, createRouter } from 'vue-router';
import { createPinia, setActivePinia } from 'pinia';

const get = vi.fn();
const post = vi.fn();

vi.mock('@/api/client', () => ({
    default: { get: (...a: unknown[]) => get(...a), post: (...a: unknown[]) => post(...a) },
}));

const { useAuthStore } = await import('@/stores/auth');
const DashboardView = (await import('./DashboardView.vue')).default;

const stub = { template: '<div />' };

function makeRouter() {
    return createRouter({
        history: createMemoryHistory(),
        routes: [
            { path: '/', name: 'home', component: stub },
            { path: '/ai-office', name: 'ai-office', component: stub },
            { path: '/ai-office/projects/:id', name: 'ai-office-project', component: stub },
            { path: '/ai-office/agents', name: 'ai-office-agents', component: stub },
            { path: '/ai-office/approvals', name: 'ai-office-approvals', component: stub },
            { path: '/ai-office/usage', name: 'ai-office-usage', component: stub },
        ],
    });
}

async function mountDashboard(role = 'manager') {
    const router = makeRouter();
    await router.push('/ai-office');
    await router.isReady();

    const auth = useAuthStore();
    auth.user = { id: 1, name: '測試', email: 't@example.com', role: role as 'manager', created_at: '' };

    const wrapper = mount(DashboardView, { global: { plugins: [router] } });
    await flushPromises();

    return { wrapper, router };
}

describe('DashboardView', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        get.mockReset();
        post.mockReset();
        get.mockImplementation((url: string) => {
            if (url === '/ai-office/projects') {
                return Promise.resolve({
                    data: {
                        data: [
                            { id: 1, name: '待辦 API', status: 'active', task_count: 3 },
                            { id: 2, name: '舊專案', status: 'completed', task_count: 9 },
                        ],
                    },
                });
            }

            if (url === '/ai-office/agents') {
                return Promise.resolve({
                    data: {
                        data: [
                            { id: 7, name: '後端小周', role: 'backend', status: 'working', model_provider: 'mock', model_name: 'mock-1', max_concurrency: 2 },
                            { id: 8, name: '前端小林', role: 'frontend', status: 'idle', model_provider: 'mock', model_name: 'mock-1', max_concurrency: 2 },
                        ],
                    },
                });
            }

            if (url === '/ai-office/approvals') {
                return Promise.resolve({ data: { data: [{ id: 3, status: 'pending', action: 'git_push', risk_level: 'high' }] } });
            }

            return Promise.resolve({ data: { data: [] } });
        });
    });

    it('統計數字全部由 API 資料算出來', async () => {
        const { wrapper } = await mountDashboard();
        const values = wrapper.findAll('.stat').map((node) => node.find('.value').text());

        // 專案 2、進行中 1、工作中的 Agent 1、待核准 1
        expect(values).toEqual(['2', '1', '1', '1']);
    });

    it('有待核准項目時把那格標成警示色的 tone', async () => {
        const { wrapper } = await mountDashboard();
        const tones = wrapper.findAll('.stat').map((node) => node.attributes('data-tone'));

        expect(tones[3]).toBe('warn');
    });

    it('點專案導到專案詳情', async () => {
        const { wrapper, router } = await mountDashboard();

        await wrapper.findAll('.project')[0].trigger('click');
        await flushPromises();

        expect(router.currentRoute.value.name).toBe('ai-office-project');
        expect(router.currentRoute.value.params.id).toBe('1');
    });

    it('建立專案送出 API 並把新專案排到最前面', async () => {
        post.mockResolvedValue({ data: { data: { id: 9, name: '新專案', status: 'planning' } } });
        const { wrapper } = await mountDashboard();

        await wrapper.findAll('.new-project input')[0].setValue('新專案');
        await wrapper.find('.new-project').trigger('submit');
        await flushPromises();

        expect(post).toHaveBeenCalledWith('/ai-office/projects', { name: '新專案', description: null });
        expect(wrapper.findAll('.project')[0].text()).toContain('新專案');
    });

    it('建立失敗時把後端訊息顯示出來，不是靜靜地什麼都沒發生', async () => {
        post.mockRejectedValue({
            isAxiosError: true,
            response: { data: { success: false, error: { code: 'VALIDATION_ERROR', message: '專案名稱已存在' } } },
        });
        const { wrapper } = await mountDashboard();

        await wrapper.findAll('.new-project input')[0].setValue('重複');
        await wrapper.find('.new-project').trigger('submit');
        await flushPromises();

        expect(wrapper.find('[role="alert"]').text()).toBe('專案名稱已存在');
    });

    it('總覽畫出像素辦公室，但沒有專案脈絡就不寫「在做什麼」', async () => {
        const { wrapper } = await mountDashboard();
        const desks = wrapper.findAll('.office-map .desk');

        expect(desks).toHaveLength(2);
        expect(wrapper.find('.office-map .task').exists()).toBe(false);
    });

    it('viewer 看不到建立表單，也看不到核准按鈕', async () => {
        const { wrapper } = await mountDashboard('viewer');

        expect(wrapper.find('.new-project').exists()).toBe(false);
        expect(wrapper.find('.approve').exists()).toBe(false);
    });
});
