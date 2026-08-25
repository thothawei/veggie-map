import { describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import AgentDesk from './AgentDesk.vue';
import type { AgentStatus, AiOfficeAgent, AiOfficeTask } from '../../types';

const agent = (status: AgentStatus = 'working'): AiOfficeAgent => ({
    id: 7,
    name: '後端小周',
    role: 'backend',
    avatar: null,
    description: null,
    status,
    model_provider: 'mock',
    model_name: 'mock-1',
    max_concurrency: 2,
});

const task: AiOfficeTask = {
    id: 1,
    project_id: 1,
    parent_task_id: null,
    title: '建立 API',
    description: null,
    status: 'running',
    priority: 50,
    assigned_agent_id: 7,
    result: null,
    error: null,
    retry_count: 0,
    max_retries: 3,
    started_at: null,
    completed_at: null,
    created_at: null,
    updated_at: null,
};

describe('AgentDesk', () => {
    it('aria-label 帶上正在處理的任務，讀螢幕的人也知道誰在忙什麼', () => {
        const wrapper = mount(AgentDesk, { props: { agent: agent(), task } });

        expect(wrapper.attributes('aria-label')).toBe('後端小周，正在處理 建立 API');
    });

    it('沒有任務時 aria-label 只有名字，不要編一個「閒置中」', () => {
        const wrapper = mount(AgentDesk, { props: { agent: agent('idle') } });

        expect(wrapper.attributes('aria-label')).toBe('後端小周');
    });

    it('桌子的狀態跟 Agent 同一個來源，螢幕亮不亮由它決定', () => {
        expect(mount(AgentDesk, { props: { agent: agent('working') } }).attributes('data-status')).toBe('working');
        expect(mount(AgentDesk, { props: { agent: agent('error') } }).attributes('data-status')).toBe('error');
    });

    it('點下去把 agent id 傳出去', async () => {
        const wrapper = mount(AgentDesk, { props: { agent: agent() } });

        await wrapper.trigger('click');

        expect(wrapper.emitted('select')).toEqual([[7]]);
    });
});
