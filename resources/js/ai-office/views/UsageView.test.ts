import { beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import { createMemoryHistory, createRouter } from 'vue-router';
import { createPinia, setActivePinia } from 'pinia';

const get = vi.fn();

vi.mock('@/api/client', () => ({ default: { get: (...a: unknown[]) => get(...a) } }));

const UsageView = (await import('./UsageView.vue')).default;

const stub = { template: '<div />' };

const report = {
    totals: { requests: 12, input_tokens: 15000, output_tokens: 4000, total_tokens: 19000, estimated_cost: '0.123400' },
    by_model: [
        { model: 'claude-opus-5', requests: 8, total_tokens: 15000, estimated_cost: '0.120000' },
        { model: 'mock-1', requests: 4, total_tokens: 4000, estimated_cost: '0.003400' },
    ],
    by_agent: [{ agent_id: 7, agent_name: '後端小周', requests: 8, total_tokens: 15000, estimated_cost: '0.120000' }],
    by_project: [
        { project_id: 1, project_name: '待辦 API', requests: 8, total_tokens: 15000, estimated_cost: '0.120000' },
        { project_id: null, project_name: null, requests: 4, total_tokens: 4000, estimated_cost: '0.003400' },
    ],
    daily: [
        { day: '2026-08-24', total_tokens: 4000, estimated_cost: '0.003400' },
        { day: '2026-08-25', total_tokens: 15000, estimated_cost: '0.120000' },
    ],
};

const performance = [
    {
        agent_id: 7, name: '後端小周', role: 'backend', status: 'working',
        tasks: 3, completed: 2, failed: 1, retries: 2, runs: 3,
        success_rate: 0.6667, avg_duration_ms: 2000, total_tokens: 15000, estimated_cost: '0.120000',
    },
    {
        agent_id: 8, name: '還沒上工的人', role: 'qa', status: 'idle',
        tasks: 0, completed: 0, failed: 0, retries: 0, runs: 0,
        success_rate: null, avg_duration_ms: null, total_tokens: 0, estimated_cost: '0.000000',
    },
];

async function mountUsage() {
    const router = createRouter({
        history: createMemoryHistory(),
        routes: [
            { path: '/ai-office', name: 'ai-office', component: stub },
            { path: '/ai-office/agents', name: 'ai-office-agents', component: stub },
            { path: '/ai-office/approvals', name: 'ai-office-approvals', component: stub },
            { path: '/ai-office/usage', name: 'ai-office-usage', component: stub },
        ],
    });
    await router.push('/ai-office/usage');
    await router.isReady();

    const wrapper = mount(UsageView, { global: { plugins: [router] } });
    await flushPromises();

    return wrapper;
}

describe('UsageView', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        get.mockReset();
        get.mockImplementation((url: string) => {
            if (url === '/ai-office/usage') {
                return Promise.resolve({
                    data: { data: report, meta: { pricing: { 'claude-opus-5': { input: 5, output: 25 } } } },
                });
            }

            if (url === '/ai-office/stats/agents') {
                return Promise.resolve({ data: { data: performance } });
            }

            if (url === '/ai-office/projects') {
                return Promise.resolve({ data: { data: [{ id: 1, name: '待辦 API', status: 'active' }] } });
            }

            return Promise.resolve({ data: { data: [] } });
        });
    });

    it('總計數字直接來自 API，金額原樣顯示不重算', async () => {
        const wrapper = await mountUsage();
        const values = wrapper.findAll('.stat .value').map((node) => node.text());

        expect(values).toEqual(['12', '15,000', '4,000', '0.123400']);
    });

    it('說明成本是用哪一份價目表估的', async () => {
        const wrapper = await mountUsage();

        expect(wrapper.find('.pricing-note').text()).toContain('claude-opus-5 $5/$25');
    });

    it('每日長條圖的高度依最大值等比例，最少也看得見', async () => {
        const wrapper = await mountUsage();
        const bars = wrapper.findAll('.bar');

        expect(bars).toHaveLength(2);
        // 4000/15000 ≈ 27%，最大的那天是 100%
        expect(bars[0].attributes('style')).toContain('height: 27%');
        expect(bars[1].attributes('style')).toContain('height: 100%');
    });

    it('沒有掛專案的用量顯示成「未指定專案」，不是空白', async () => {
        const wrapper = await mountUsage();

        expect(wrapper.text()).toContain('（未指定專案）');
    });

    it('Agent 效能表把 null 成功率寫成破折號，不是 0%', async () => {
        const wrapper = await mountUsage();
        const rows = wrapper.findAll('.performance tbody tr');

        expect(rows[0].text()).toContain('67%');
        expect(rows[0].text()).toContain('2.0 秒');
        expect(rows[1].text()).toContain('—');
        expect(rows[1].text()).not.toContain('0%');
    });

    it('套用篩選時把日期帶進查詢，空字串不送出去', async () => {
        const wrapper = await mountUsage();
        get.mockClear();

        await wrapper.find('input[type="date"]').setValue('2026-08-01');
        await wrapper.find('form').trigger('submit');
        await flushPromises();

        const call = get.mock.calls.find(([url]) => url === '/ai-office/usage');

        expect(call?.[1].params).toEqual({ from: '2026-08-01' });
    });

    it('載入失敗時顯示錯誤，不是留一片 0', async () => {
        get.mockRejectedValue(new Error('boom'));
        const wrapper = await mountUsage();

        expect(wrapper.find('[role="alert"]').text()).toBe('載入用量資料失敗');
    });
});
