import { describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import AgentStatusBadge from './AgentStatusBadge.vue';

describe('AgentStatusBadge', () => {
    it('顯示中文狀態，原始狀態碼留在 data-status 上', () => {
        const wrapper = mount(AgentStatusBadge, { props: { status: 'waiting_review' } });

        expect(wrapper.text()).toBe('等待核准');
        expect(wrapper.attributes('data-status')).toBe('waiting_review');
    });

    it('compact 模式仍然把狀態留給輔助技術，不是只剩一個色塊', () => {
        const wrapper = mount(AgentStatusBadge, { props: { status: 'error', compact: true } });

        expect(wrapper.find('.text').exists()).toBe(false);
        expect(wrapper.find('.sr-only').text()).toBe('錯誤');
    });
});
