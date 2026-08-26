import { describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import MessageFeed from './MessageFeed.vue';
import type { AiOfficeMessage } from '../../types';

const messages: AiOfficeMessage[] = [{
    id: 1,
    task_id: 5,
    content: '請處理「建立 API」。',
    from: { id: 1, name: 'AI 主管 Michael', role: 'ceo' },
    to: { id: 2, name: '後端阿明', role: 'backend' },
    created_at: '2026-08-26T10:41:00+08:00',
}];

describe('MessageFeed', () => {
    /**
     * 這個面板存在的理由就是「誰對誰」——只印內容的話它就退化成第二份事件流。
     */
    it('顯示收發雙方與內容', () => {
        const wrapper = mount(MessageFeed, { props: { messages } });

        expect(wrapper.find('.who').text()).toBe('AI 主管 Michael → 後端阿明');
        expect(wrapper.find('.content').text()).toContain('建立 API');
        expect(wrapper.find('.time').text()).toBe('08/26 10:41');
    });

    it('Agent 被刪掉時顯示破折號，不是空白或 undefined', () => {
        const wrapper = mount(MessageFeed, {
            props: { messages: [{ ...messages[0], to: null }] },
        });

        expect(wrapper.find('.who').text()).toBe('AI 主管 Michael → —');
    });

    it('沒有訊息時給提示，不留一片空白', () => {
        const wrapper = mount(MessageFeed, { props: { messages: [] } });

        expect(wrapper.find('.hint').text()).toBe('還沒有 Agent 之間的訊息。');
    });
});
