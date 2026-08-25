import { describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import OfficeMap from './OfficeMap.vue';
import type { AgentStatus, AiOfficeAgent, AiOfficeTask, TaskStatus } from '../../types';

function agent(id: number, role: string, status: AgentStatus = 'idle', name = `Agent ${id}`): AiOfficeAgent {
    return {
        id,
        name,
        role,
        avatar: null,
        description: null,
        status,
        model_provider: 'mock',
        model_name: 'mock-1',
        max_concurrency: 2,
    };
}

function task(id: number, agentId: number | null, status: TaskStatus = 'running'): AiOfficeTask {
    return {
        id,
        project_id: 1,
        parent_task_id: null,
        title: `任務 ${id}`,
        description: null,
        status,
        priority: 50,
        assigned_agent_id: agentId,
        result: null,
        error: null,
        retry_count: 0,
        max_retries: 3,
        started_at: null,
        completed_at: null,
        created_at: null,
        updated_at: null,
    };
}

describe('OfficeMap', () => {
    it('依角色分房，CEO 排第一間、未知角色排最後', () => {
        const wrapper = mount(OfficeMap, {
            props: { agents: [agent(1, 'qa'), agent(2, 'marketing'), agent(3, 'ceo'), agent(4, 'backend')] },
        });

        expect(wrapper.findAll('.room h3').map((node) => node.text()))
            .toEqual(['CEO 辦公室', '後端', 'QA', 'marketing']);
    });

    it('同一個角色的 Agent 共用一間房', () => {
        const wrapper = mount(OfficeMap, {
            props: { agents: [agent(1, 'backend'), agent(2, 'backend'), agent(3, 'frontend')] },
        });

        const rooms = wrapper.findAll('.room');

        expect(rooms).toHaveLength(2);
        expect(rooms[0].findAll('.desk')).toHaveLength(2);
    });

    it('桌上顯示那位 Agent 正在跑的任務', () => {
        const wrapper = mount(OfficeMap, {
            props: {
                agents: [agent(7, 'backend', 'working', '後端小周')],
                tasks: [task(1, 7)],
            },
        });

        expect(wrapper.find('.desk .task').text()).toBe('任務 1');
    });

    it('只認 running 的任務——待處理的不算「正在做」', () => {
        const wrapper = mount(OfficeMap, {
            props: {
                agents: [agent(7, 'backend', 'idle')],
                tasks: [task(1, 7, 'pending'), task(2, 7, 'completed')],
            },
        });

        expect(wrapper.find('.desk .task').exists()).toBe(false);
    });

    it('同一個人有多個 running 時取最早派的那個，不是隨機挑', () => {
        const wrapper = mount(OfficeMap, {
            props: { agents: [agent(7, 'backend', 'working')], tasks: [task(9, 7), task(4, 7)] },
        });

        expect(wrapper.find('.desk .task').text()).toBe('任務 4');
    });

    it('沒有傳 tasks 時不顯示任務行，不要瞎猜', () => {
        const wrapper = mount(OfficeMap, { props: { agents: [agent(7, 'backend', 'working')] } });

        expect(wrapper.find('.desk .task').exists()).toBe(false);
    });

    it('沒有 Agent 時給明確提示', () => {
        const wrapper = mount(OfficeMap, { props: { agents: [] } });

        expect(wrapper.find('.empty').text()).toContain('辦公室還沒有人');
    });

    it('點桌子把 agent id 傳出去', async () => {
        const wrapper = mount(OfficeMap, { props: { agents: [agent(42, 'devops')] } });

        await wrapper.find('.desk').trigger('click');

        expect(wrapper.emitted('select')).toEqual([[42]]);
    });
});
