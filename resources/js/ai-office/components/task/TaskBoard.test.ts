import { describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import TaskBoard from './TaskBoard.vue';
import type { AiOfficeAgent, AiOfficeTask, TaskStatus } from '../../types';

function task(id: number, status: TaskStatus, agentId: number | null = null): AiOfficeTask {
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

const agents: AiOfficeAgent[] = [{
    id: 7,
    name: '後端小周',
    role: 'backend',
    avatar: null,
    description: null,
    status: 'working',
    model_provider: 'mock',
    model_name: 'mock-1',
    max_concurrency: 2,
}];

describe('TaskBoard', () => {
    it('依狀態分欄，空的狀態不佔版面', () => {
        const wrapper = mount(TaskBoard, {
            props: { tasks: [task(1, 'running'), task(2, 'completed'), task(3, 'running')], agents },
        });

        const columns = wrapper.findAll('.column');

        expect(columns).toHaveLength(2);
        // 欄位順序照後端 TASK_STATUSES，不是照任務出現的順序。
        expect(columns[0].attributes('data-status')).toBe('running');
        expect(columns[0].findAll('.task-card')).toHaveLength(2);
        expect(columns[1].attributes('data-status')).toBe('completed');
    });

    it('顯示負責 Agent 的名字，沒指派就寫未指派', () => {
        const wrapper = mount(TaskBoard, {
            props: { tasks: [task(1, 'running', 7), task(2, 'running')], agents },
        });

        const names = wrapper.findAll('.agent').map((node) => node.text());

        expect(names).toEqual(['後端小周', '未指派']);
    });

    it('沒有任務時給提示，不是留一片空白', () => {
        const wrapper = mount(TaskBoard, { props: { tasks: [], agents } });

        expect(wrapper.find('.hint').text()).toContain('還沒有任務');
    });

    it('點卡片把 task id 傳出去', async () => {
        const wrapper = mount(TaskBoard, { props: { tasks: [task(42, 'running')], agents } });

        await wrapper.find('.task-card').trigger('click');

        expect(wrapper.emitted('select')).toEqual([[42]]);
    });

    it('用 aria-pressed 表示哪一張被選取', () => {
        const wrapper = mount(TaskBoard, {
            props: { tasks: [task(1, 'running'), task(2, 'running')], agents, selectedId: 2 },
        });

        expect(wrapper.findAll('.task-card').map((node) => node.attributes('aria-pressed')))
            .toEqual(['false', 'true']);
    });
});
