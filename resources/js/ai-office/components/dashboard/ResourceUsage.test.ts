import { describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import ResourceUsage from './ResourceUsage.vue';
import type { ResourceUsageSnapshot } from '../../types';

function snapshot(overrides: Partial<ResourceUsageSnapshot> = {}): ResourceUsageSnapshot {
    return {
        source: 'application',
        host_load: { available: true, one: 0.5, five: 0.4, fifteen: 0.3 },
        php_memory: { used_bytes: 2 * 1024 * 1024, peak_bytes: 4 * 1024 * 1024, limit: '128M' },
        queue: { connection: 'redis', queue: 'ai-office', pending_jobs: 3 },
        tasks: { running_tasks: 2, waiting_review_tasks: 1, working_agents: 1 },
        sandbox: { docker_available: true, docker_tool_enabled: false, cpu_limit: '1.0', memory_limit_mb: 512 },
        ...overrides,
    };
}

describe('ResourceUsage', () => {
    it('顯示載入中，且不假裝已經有資料', () => {
        const wrapper = mount(ResourceUsage, { props: { snapshot: null, loading: true } });

        expect(wrapper.text()).toContain('載入中');
        expect(wrapper.find('.grid').exists()).toBe(false);
    });

    it('載入失敗時顯示錯誤，不用 0 佔位', () => {
        const wrapper = mount(ResourceUsage, { props: { snapshot: null, loading: false } });

        expect(wrapper.find('[role="alert"]').exists()).toBe(true);
    });

    /**
     * 規格第 39 節的硬性要求：不是真的 host CPU/Memory，UI 必須標示資料來源。
     * 這條測試釘住那行字真的存在，不是可以被之後改動悄悄拿掉的裝飾。
     */
    it('永遠標示資料來源不是真的 host 監控', () => {
        const wrapper = mount(ResourceUsage, { props: { snapshot: snapshot(), loading: false } });

        expect(wrapper.find('.source').text()).toContain('應用層量測');
    });

    it('數字直接來自 snapshot，不是寫死的', () => {
        const wrapper = mount(ResourceUsage, {
            props: { snapshot: snapshot({ tasks: { running_tasks: 9, waiting_review_tasks: 1, working_agents: 1 } }), loading: false },
        });

        const values = wrapper.findAll('.item').map((node) => node.find('.value').text());
        expect(values).toContain('9');
    });

    it('host load 拿不到時老實說拿不到，不是顯示 0', () => {
        const wrapper = mount(ResourceUsage, {
            props: { snapshot: snapshot({ host_load: { available: false, one: null, five: null, fifteen: null } }), loading: false },
        });

        const values = wrapper.findAll('.item').map((node) => node.find('.value').text());
        expect(values[0]).toBe('無法取得');
    });

    it('PHP 記憶體用量換算成人類看得懂的單位', () => {
        const wrapper = mount(ResourceUsage, { props: { snapshot: snapshot(), loading: false } });

        const values = wrapper.findAll('.item').map((node) => node.find('.value').text());
        expect(values[1]).toBe('2.0 MB');
    });
});
