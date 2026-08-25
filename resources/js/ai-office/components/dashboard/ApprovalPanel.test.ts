import { describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import ApprovalPanel from './ApprovalPanel.vue';
import type { AiOfficeApproval } from '../../types';

function approval(id: number, risk: AiOfficeApproval['risk_level'] = 'high'): AiOfficeApproval {
    return {
        id,
        project_id: 1,
        task_id: 2,
        agent_id: 3,
        tool_execution_id: null,
        action: 'git_push',
        risk_level: risk,
        reason: '推到 main 需要人工確認',
        payload: null,
        status: 'pending',
        approved_by: null,
        approved_at: null,
        rejected_by: null,
        rejected_at: null,
        expires_at: null,
        created_at: '2026-08-25T10:00:00+08:00',
    };
}

describe('ApprovalPanel', () => {
    it('沒有待核准項目時明講，不是顯示空清單', () => {
        const wrapper = mount(ApprovalPanel, { props: { approvals: [] } });

        expect(wrapper.find('.hint').text()).toContain('沒有需要人工核准');
    });

    it('核准／拒絕按鈕只給有權限的人看', () => {
        const readOnly = mount(ApprovalPanel, { props: { approvals: [approval(1)], canDecide: false } });

        expect(readOnly.find('.approve').exists()).toBe(false);
        expect(readOnly.text()).toContain('只有 admin／manager 可以核准');

        const manager = mount(ApprovalPanel, { props: { approvals: [approval(1)], canDecide: true } });

        expect(manager.find('.approve').exists()).toBe(true);
    });

    it('送出決定時帶上備註', async () => {
        const wrapper = mount(ApprovalPanel, { props: { approvals: [approval(5)], canDecide: true } });

        await wrapper.find('input').setValue('  確認過了  ');
        await wrapper.find('.approve').trigger('click');

        expect(wrapper.emitted('decide')).toEqual([[{ id: 5, decision: 'approve', comment: '確認過了' }]]);
    });

    it('沒填備註就送 null，不要送空字串', async () => {
        const wrapper = mount(ApprovalPanel, { props: { approvals: [approval(5)], canDecide: true } });

        await wrapper.find('.reject').trigger('click');

        expect(wrapper.emitted('decide')).toEqual([[{ id: 5, decision: 'reject', comment: null }]]);
    });

    it('風險等級用中文顯示並標在 data-risk 上', () => {
        const wrapper = mount(ApprovalPanel, { props: { approvals: [approval(1, 'critical')] } });

        expect(wrapper.find('.risk').text()).toBe('極高風險');
        expect(wrapper.find('.risk').attributes('data-risk')).toBe('critical');
    });
});
