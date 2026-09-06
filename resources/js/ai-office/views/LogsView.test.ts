import { beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import { createMemoryHistory, createRouter } from 'vue-router';
import { createPinia, setActivePinia } from 'pinia';

const get = vi.fn();

vi.mock('@/api/client', () => ({ default: { get: (...a: unknown[]) => get(...a) } }));

const LogsView = (await import('./LogsView.vue')).default;

const stub = { template: '<div />' };

function entry(id: number, overrides: Record<string, unknown> = {}) {
    return {
        id,
        project_id: 1,
        project_name: '待辦 API',
        task_id: null,
        agent_id: null,
        type: 'TaskCompleted',
        description: `事件 ${id}`,
        payload: null,
        created_at: '2026-09-06T10:00:00+00:00',
        ...overrides,
    };
}

function makeRouter() {
    return createRouter({
        history: createMemoryHistory(),
        routes: [
            { path: '/ai-office', name: 'ai-office', component: stub },
            { path: '/ai-office/agents', name: 'ai-office-agents', component: stub },
            { path: '/ai-office/approvals', name: 'ai-office-approvals', component: stub },
            { path: '/ai-office/usage', name: 'ai-office-usage', component: stub },
            { path: '/ai-office/logs', name: 'ai-office-logs', component: stub },
        ],
    });
}

async function mountLogs() {
    const router = makeRouter();
    await router.push('/ai-office/logs');
    await router.isReady();

    const wrapper = mount(LogsView, { global: { plugins: [router] } });
    await flushPromises();

    return wrapper;
}

describe('LogsView', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        get.mockReset();
        get.mockImplementation((url: string) => {
            if (url === '/ai-office/projects') {
                return Promise.resolve({ data: { data: [{ id: 1, name: '待辦 API', status: 'active', task_count: 3 }] } });
            }

            if (url === '/ai-office/activities') {
                return Promise.resolve({
                    data: {
                        data: [entry(2), entry(1)],
                        meta: { current_page: 1, last_page: 1, total: 2 },
                    },
                });
            }

            return Promise.resolve({ data: { data: [] } });
        });
    });

    it('列出跨專案的紀錄，含專案名稱', async () => {
        const wrapper = await mountLogs();

        const rows = wrapper.findAll('.log-list li');
        expect(rows).toHaveLength(2);
        expect(rows[0].find('.project').text()).toBe('待辦 API');
        expect(rows[0].find('.description').text()).toBe('事件 2');
    });

    it('沒有紀錄時說明白，不是空白畫面', async () => {
        get.mockImplementation((url: string) => {
            if (url === '/ai-office/activities') {
                return Promise.resolve({ data: { data: [], meta: { current_page: 1, last_page: 1, total: 0 } } });
            }

            return Promise.resolve({ data: { data: [] } });
        });

        const wrapper = await mountLogs();

        expect(wrapper.text()).toContain('沒有符合條件的紀錄');
    });

    it('載入失敗時顯示錯誤', async () => {
        get.mockImplementation((url: string) => {
            if (url === '/ai-office/activities') return Promise.reject(new Error('boom'));

            return Promise.resolve({ data: { data: [] } });
        });

        const wrapper = await mountLogs();

        expect(wrapper.find('[role="alert"]').text()).toBe('載入紀錄失敗');
    });

    it('套用篩選時把 project_id 與 type 帶給後端', async () => {
        const wrapper = await mountLogs();

        await wrapper.find('select').setValue('1');
        await wrapper.find('input[type="text"]').setValue('TaskCompleted');
        await wrapper.find('form').trigger('submit');
        await flushPromises();

        expect(get).toHaveBeenCalledWith('/ai-office/activities', {
            params: { project_id: 1, type: 'TaskCompleted', page: 1 },
        });
    });

    it('超過一頁時顯示分頁控制，點下一頁會帶 page 參數', async () => {
        get.mockImplementation((url: string) => {
            if (url === '/ai-office/projects') return Promise.resolve({ data: { data: [] } });
            if (url === '/ai-office/activities') {
                return Promise.resolve({
                    data: { data: [entry(1)], meta: { current_page: 1, last_page: 2, total: 2 } },
                });
            }

            return Promise.resolve({ data: { data: [] } });
        });

        const wrapper = await mountLogs();

        expect(wrapper.find('.pagination').exists()).toBe(true);

        await wrapper.findAll('.pagination button')[1].trigger('click');
        await flushPromises();

        expect(get).toHaveBeenLastCalledWith('/ai-office/activities', {
            params: { page: 2 },
        });
    });
});
