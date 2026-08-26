import { describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import TaskGraph from './TaskGraph.vue';
import type { AiOfficeTask } from '../../types';

function task(id: number, title: string, dependencies: number[] = [], status = 'pending'): AiOfficeTask {
    return {
        id,
        project_id: 1,
        title,
        description: null,
        status,
        priority: 0,
        assigned_agent_id: null,
        retry_count: 0,
        max_retries: 3,
        dependencies,
    } as unknown as AiOfficeTask;
}

/** 節點的 x 座標＝它在第幾層，用來斷言「左邊做完才輪到右邊」。 */
function columnOf(wrapper: ReturnType<typeof mount>, id: number): number {
    const node = wrapper.find(`.node[data-id="${id}"] rect`);

    return Number(node.attributes('x'));
}

describe('TaskGraph', () => {
    it('沒有相依的任務排在最左邊，相依者往右一層', () => {
        const wrapper = mount(TaskGraph, {
            props: { tasks: [task(1, '設計資料庫'), task(2, '建立 API', [1])] },
        });

        expect(columnOf(wrapper, 1)).toBe(0);
        expect(columnOf(wrapper, 2)).toBeGreaterThan(0);
    });

    it('層數是最長的相依鏈，不是相依數量', () => {
        // 3 同時相依 1（第 0 層）與 2（第 1 層），所以它是第 2 層。
        const wrapper = mount(TaskGraph, {
            props: { tasks: [task(1, 'A'), task(2, 'B', [1]), task(3, 'C', [1, 2])] },
        });

        expect(columnOf(wrapper, 3)).toBeGreaterThan(columnOf(wrapper, 2));
    });

    it('每個相依關係畫一條邊', () => {
        const wrapper = mount(TaskGraph, {
            props: { tasks: [task(1, 'A'), task(2, 'B', [1]), task(3, 'C', [1, 2])] },
        });

        expect(wrapper.findAll('.edge')).toHaveLength(3);
    });

    /**
     * 後端會擋住會成環的相依，但這個元件不該假設拿到的資料一定乾淨（舊資料、
     * 手動改過的 DB、之後新增的寫入路徑）。環會讓天真的遞迴無限展開，整個畫面當掉。
     */
    it('資料裡有環時仍然畫得出來，不會無限遞迴', () => {
        const wrapper = mount(TaskGraph, {
            props: { tasks: [task(1, 'A', [2]), task(2, 'B', [1])] },
        });

        expect(wrapper.findAll('.node')).toHaveLength(2);
    });

    it('相依指向不在這一頁的任務時不畫線，不是連到 (0,0)', () => {
        const wrapper = mount(TaskGraph, {
            props: { tasks: [task(2, 'B', [999])] },
        });

        expect(wrapper.findAll('.edge')).toHaveLength(0);
        expect(wrapper.findAll('.node')).toHaveLength(1);
    });

    it('點節點會發出 select 事件', async () => {
        const wrapper = mount(TaskGraph, { props: { tasks: [task(1, 'A')] } });

        await wrapper.find('.node').trigger('click');

        expect(wrapper.emitted('select')?.[0]).toEqual([1]);
    });

    it('沒有任務時給提示，不留一片空白', () => {
        const wrapper = mount(TaskGraph, { props: { tasks: [] } });

        expect(wrapper.find('.hint').text()).toBe('還沒有任務。');
    });

    it('狀態標籤用中文，未知狀態顯示原始值不炸掉', () => {
        const wrapper = mount(TaskGraph, {
            props: { tasks: [task(1, 'A', [], 'running'), task(2, 'B', [], 'some_new_status')] },
        });

        const labels = wrapper.findAll('.node text.status').map((node) => node.text());
        expect(labels).toContain('執行中');
        expect(labels).toContain('some_new_status');
    });
});
