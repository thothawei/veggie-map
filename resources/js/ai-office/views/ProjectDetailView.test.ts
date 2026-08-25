import { beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import { createMemoryHistory, createRouter } from 'vue-router';
import { createPinia, setActivePinia } from 'pinia';

const get = vi.fn();
const post = vi.fn();
const patch = vi.fn();

vi.mock('@/api/client', () => ({
    default: {
        get: (...a: unknown[]) => get(...a),
        post: (...a: unknown[]) => post(...a),
        patch: (...a: unknown[]) => patch(...a),
    },
}));

class FakeEventSource {
    static instances: FakeEventSource[] = [];

    onerror: (() => void) | null = null;

    closed = false;

    private listeners: Record<string, ((event: MessageEvent) => void)[]> = {};

    constructor(public url: string) {
        FakeEventSource.instances.push(this);
    }

    addEventListener(type: string, handler: (event: MessageEvent) => void): void {
        (this.listeners[type] ??= []).push(handler);
    }

    emit(type: string, data: unknown): void {
        (this.listeners[type] ?? []).forEach((handler) => handler({ data: JSON.stringify(data) } as MessageEvent));
    }

    close(): void {
        this.closed = true;
    }
}

const { useAuthStore } = await import('@/stores/auth');
const ProjectDetailView = (await import('./ProjectDetailView.vue')).default;

const stub = { template: '<div />' };
let tasksPayload: unknown[] = [];
let agentsPayload: unknown[] = [];

function activity(id: number, type: string) {
    return { id, project_id: 1, task_id: 1, agent_id: null, type, description: `事件 ${id}`, payload: null, created_at: null };
}

async function mountView(role = 'developer') {
    const router = createRouter({
        history: createMemoryHistory(),
        routes: [
            { path: '/', name: 'home', component: stub },
            { path: '/ai-office', name: 'ai-office', component: stub },
            { path: '/ai-office/agents', name: 'ai-office-agents', component: stub },
            { path: '/ai-office/approvals', name: 'ai-office-approvals', component: stub },
        ],
    });
    await router.push('/ai-office');
    await router.isReady();

    const auth = useAuthStore();
    auth.user = { id: 1, name: '測試', email: 't@example.com', role: role as 'developer', created_at: '' };

    const wrapper = mount(ProjectDetailView, { props: { id: '1' }, global: { plugins: [router] } });
    await flushPromises();

    return wrapper;
}

describe('ProjectDetailView', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        vi.stubGlobal('EventSource', FakeEventSource);
        FakeEventSource.instances = [];
        get.mockReset();
        post.mockReset();
        patch.mockReset();

        tasksPayload = [
            { id: 1, project_id: 1, title: '建立 API', status: 'running', priority: 60, assigned_agent_id: 7, retry_count: 0, max_retries: 3 },
            { id: 2, project_id: 1, title: '寫測試', status: 'pending', priority: 50, assigned_agent_id: null, retry_count: 0, max_retries: 3 },
        ];

        agentsPayload = [{ id: 7, name: '後端小周', role: 'backend', status: 'working', model_provider: 'mock', model_name: 'mock-1', max_concurrency: 2 }];

        get.mockImplementation((url: string) => {
            if (url === '/ai-office/projects/1') {
                return Promise.resolve({ data: { data: { id: 1, name: '待辦 API', status: 'active' } } });
            }

            if (url === '/ai-office/tasks/1') {
                return Promise.resolve({
                    data: { data: { ...(tasksPayload[0] as object), dependencies: [], dependencies_satisfied: true } },
                });
            }

            if (url === '/ai-office/projects/1/tasks') {
                return Promise.resolve({ data: { data: tasksPayload } });
            }

            if (url === '/ai-office/agents') {
                return Promise.resolve({ data: { data: agentsPayload } });
            }

            if (url === '/ai-office/projects/1/activities') {
                return Promise.resolve({ data: { data: [activity(5, 'TaskStarted')], meta: { latest_id: 5 } } });
            }

            return Promise.resolve({ data: { data: [] } });
        });

        post.mockImplementation((url: string) => {
            if (url === '/ai-office/projects/1/events/ticket') {
                return Promise.resolve({ data: { data: { ticket: 'tkt', expires_in: 60, latest_id: 5 } } });
            }

            return Promise.resolve({ data: { data: {} } });
        });
    });

    it('載入後畫出任務看板與事件流，並開了一條串流', async () => {
        const wrapper = await mountView();

        expect(wrapper.findAll('.column')).toHaveLength(2);
        expect(wrapper.find('.events li').text()).toContain('事件 5');
        expect(FakeEventSource.instances).toHaveLength(1);
    });

    it('任務統計來自任務清單', async () => {
        const wrapper = await mountView();
        const values = wrapper.findAll('.stat').map((node) => node.find('.value').text());

        // 總數 2、執行中 1、已完成 0、失敗 0
        expect(values).toEqual(['2', '1', '0', '0']);
    });

    it('收到任務事件時重抓任務，不是憑事件 payload 自己猜狀態', async () => {
        const wrapper = await mountView();
        const before = get.mock.calls.filter(([url]) => url === '/ai-office/projects/1/tasks').length;

        tasksPayload = [{ ...(tasksPayload[0] as object), status: 'completed' }, tasksPayload[1]];
        FakeEventSource.instances[0].emit('activity', activity(6, 'TaskStatusChanged'));
        await flushPromises();

        const after = get.mock.calls.filter(([url]) => url === '/ai-office/projects/1/tasks').length;
        expect(after).toBe(before + 1);
        expect(wrapper.findAll('.column').map((node) => node.attributes('data-status')))
            .toEqual(['pending', 'completed']);
    });

    it('與任務無關的事件不會觸發重抓', async () => {
        await mountView();
        const before = get.mock.calls.filter(([url]) => url === '/ai-office/projects/1/tasks').length;

        FakeEventSource.instances[0].emit('activity', activity(7, 'ProjectPlanned'));
        await flushPromises();

        const after = get.mock.calls.filter(([url]) => url === '/ai-office/projects/1/tasks').length;
        expect(after).toBe(before);
    });

    it('點任務打開詳情，關閉後收起來', async () => {
        const wrapper = await mountView();

        // 欄位順序照 TASK_STATUSES（pending 在 running 前面），所以要指名找那張卡，
        // 不能假設第一張就是 id=1。
        const card = wrapper.findAll('.task-card').find((node) => node.text().includes('建立 API'));
        await card?.trigger('click');
        await flushPromises();

        expect(wrapper.find('.task-detail').exists()).toBe(true);
        expect(wrapper.find('.task-detail h2').text()).toBe('建立 API');

        await wrapper.find('.task-detail .close').trigger('click');
        expect(wrapper.find('.task-detail').exists()).toBe(false);
    });

    it('像素辦公室顯示那位 Agent 正在跑的任務，跟看板同一份資料', async () => {
        const wrapper = await mountView();
        const desk = wrapper.find('.office .desk');

        expect(desk.attributes('data-status')).toBe('working');
        expect(desk.find('.task').text()).toBe('建立 API');
        expect(desk.attributes('aria-label')).toBe('後端小周，正在處理 建立 API');
    });

    it('Agent 狀態事件進來時像素辦公室跟著換狀態，不用重新整理', async () => {
        const wrapper = await mountView();
        expect(wrapper.find('.office .desk').attributes('data-status')).toBe('working');

        agentsPayload = [{ ...(agentsPayload[0] as object), status: 'error' }];
        FakeEventSource.instances[0].emit('activity', activity(8, 'AgentStatusChanged'));
        await flushPromises();

        expect(wrapper.find('.office .desk').attributes('data-status')).toBe('error');
    });

    it('離開頁面時關掉串流，不佔著後端的連線名額', async () => {
        const wrapper = await mountView();

        wrapper.unmount();

        expect(FakeEventSource.instances[0].closed).toBe(true);
    });
});
