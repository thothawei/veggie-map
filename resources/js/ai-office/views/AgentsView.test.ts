import { beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import { createMemoryHistory, createRouter } from 'vue-router';
import { createPinia, setActivePinia } from 'pinia';

const get = vi.fn();

vi.mock('@/api/client', () => ({ default: { get: (...a: unknown[]) => get(...a) } }));

const AgentsView = (await import('./AgentsView.vue')).default;

const stub = { template: '<div />' };

const agent = {
    id: 7, name: '後端小周', role: 'backend', status: 'idle', avatar: null, description: '負責後端',
    model_provider: 'mock', model_name: 'mock-1', max_concurrency: 2,
};

function memory(id: number, importance: number, content: string) {
    return {
        id,
        agent_id: 7,
        project_id: 1,
        memory_type: 'error_pattern',
        content,
        importance,
        created_at: null,
    };
}

async function mountAgents() {
    const router = createRouter({
        history: createMemoryHistory(),
        routes: [
            { path: '/ai-office', name: 'ai-office', component: stub },
            { path: '/ai-office/agents', name: 'ai-office-agents', component: stub },
            { path: '/ai-office/approvals', name: 'ai-office-approvals', component: stub },
            { path: '/ai-office/usage', name: 'ai-office-usage', component: stub },
        ],
    });
    await router.push('/ai-office/agents');
    await router.isReady();

    const wrapper = mount(AgentsView, { global: { plugins: [router] } });
    await flushPromises();

    return wrapper;
}

describe('AgentsView 的記憶區塊', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        get.mockReset();
        get.mockImplementation((url: string) => {
            if (url === '/ai-office/agents') {
                return Promise.resolve({ data: { data: [agent] } });
            }

            if (url === '/ai-office/agents/7') {
                return Promise.resolve({
                    data: { data: { ...agent, system_prompt: '你是後端工程師', tools: ['file'], permissions: { read_file: 'allow' } } },
                });
            }

            if (url === '/ai-office/agents/7/memories') {
                return Promise.resolve({
                    data: {
                        data: [memory(1, 9, '上次因為缺 migration 掛掉'), memory(2, 3, '普通的一則')],
                        meta: { recall_limit: 1 },
                    },
                });
            }

            return Promise.resolve({ data: { data: [] } });
        });
    });

    it('點 Agent 之後同時載入詳情與記憶', async () => {
        const wrapper = await mountAgents();

        await wrapper.find('.agent-card').trigger('click');
        await flushPromises();

        expect(wrapper.findAll('.memories li')).toHaveLength(2);
        expect(wrapper.find('.memories li .content').text()).toBe('上次因為缺 migration 掛掉');
        expect(wrapper.find('.memories li .type').text()).toBe('失敗模式');
    });

    it('只有會進下次 prompt 的那幾則標成 recalled', async () => {
        const wrapper = await mountAgents();

        await wrapper.find('.agent-card').trigger('click');
        await flushPromises();

        const items = wrapper.findAll('.memories li');

        // recall_limit=1，所以只有第一則會真的被送進 prompt。
        expect(items[0].classes()).toContain('recalled');
        expect(items[1].classes()).not.toContain('recalled');
        expect(wrapper.text()).toContain('前 1 則會進下次的 prompt');
    });

    it('沒有記憶時說明它是怎麼長出來的，而不是一片空白', async () => {
        get.mockImplementation((url: string) => {
            if (url === '/ai-office/agents') return Promise.resolve({ data: { data: [agent] } });
            if (url === '/ai-office/agents/7') return Promise.resolve({ data: { data: { ...agent, tools: [], permissions: {} } } });

            return Promise.resolve({ data: { data: [], meta: { recall_limit: 5 } } });
        });

        const wrapper = await mountAgents();
        await wrapper.find('.agent-card').trigger('click');
        await flushPromises();

        expect(wrapper.text()).toContain('還沒有記憶');
    });
});
