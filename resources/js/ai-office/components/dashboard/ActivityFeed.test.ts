import { describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import ActivityFeed from './ActivityFeed.vue';
import type { AiOfficeActivity } from '../../types';

const activities: AiOfficeActivity[] = [{
    id: 9,
    project_id: 1,
    task_id: 2,
    agent_id: 3,
    type: 'TaskCompleted',
    description: '後端小周 完成「建立 API」',
    payload: null,
    created_at: '2026-08-25T17:42:00+08:00',
}];

describe('ActivityFeed', () => {
    it('輪詢模式要老實說不是即時', () => {
        const wrapper = mount(ActivityFeed, { props: { activities, state: 'polling' } });

        expect(wrapper.find('.state').text()).toContain('非即時');
    });

    it('連上線時顯示即時', () => {
        const wrapper = mount(ActivityFeed, { props: { activities, state: 'live' } });

        expect(wrapper.find('.state').text()).toBe('即時');
    });

    it('事件顯示時間、類型與描述', () => {
        const wrapper = mount(ActivityFeed, { props: { activities, state: 'live' } });
        const row = wrapper.find('.events li');

        expect(row.find('.time').text()).toBe('08/25 17:42');
        expect(row.find('.type').text()).toBe('TaskCompleted');
        expect(row.find('.description').text()).toContain('完成「建立 API」');
    });

    it('沒有事件時給提示', () => {
        const wrapper = mount(ActivityFeed, { props: { activities: [], state: 'live' } });

        expect(wrapper.find('.hint').text()).toBe('還沒有事件。');
    });
});
