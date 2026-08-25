import { beforeEach, describe, expect, it, vi } from 'vitest';
import { createPinia, setActivePinia } from 'pinia';

const post = vi.fn();
const get = vi.fn();
const patch = vi.fn();

vi.mock('@/api/client', () => ({
    default: {
        get: (...args: unknown[]) => get(...args),
        post: (...args: unknown[]) => post(...args),
        patch: (...args: unknown[]) => patch(...args),
    },
}));

const { useApprovalsStore } = await import('./approvals');
const { useTasksStore } = await import('./tasks');
const { useProjectsStore } = await import('./projects');

function approval(id: number, status = 'pending') {
    return { id, status, action: 'git_push', risk_level: 'high' };
}

function task(id: number, status: string) {
    return { id, status, title: `任務 ${id}`, priority: 50, assigned_agent_id: null, retry_count: 0, max_retries: 3 };
}

describe('AI Office stores', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        get.mockReset();
        post.mockReset();
        patch.mockReset();
    });

    it('核准後把該筆從待處理清單移除', async () => {
        get.mockResolvedValue({ data: { data: [approval(1), approval(2)] } });
        post.mockResolvedValue({ data: { data: { ...approval(1), status: 'approved' } } });

        const store = useApprovalsStore();
        await store.fetchPending();
        expect(store.pendingCount).toBe(2);

        await store.decide(1, 'approve', '確認過');

        expect(post).toHaveBeenCalledWith('/ai-office/approvals/1/approve', { comment: '確認過' });
        expect(store.approvals.map((item) => item.id)).toEqual([2]);
    });

    it('載入失敗時留下錯誤訊息而不是靜靜地空白', async () => {
        get.mockRejectedValue(new Error('boom'));

        const store = useApprovalsStore();
        await store.fetchPending();

        expect(store.error).toBe('載入待核准項目失敗');
        expect(store.loading).toBe(false);
    });

    it('任務看板的分組每個狀態都有一格，即使是空的', async () => {
        get.mockResolvedValue({ data: { data: [task(1, 'running'), task(2, 'running'), task(3, 'failed')] } });

        const store = useTasksStore();
        await store.fetchForProject(1);

        expect(store.byStatus.running).toHaveLength(2);
        expect(store.byStatus.failed).toHaveLength(1);
        expect(store.byStatus.completed).toEqual([]);
    });

    it('改狀態後就地換掉那一筆，連同開著的詳情一起更新', async () => {
        get.mockResolvedValue({ data: { data: [task(1, 'pending')] } });
        patch.mockResolvedValue({ data: { data: task(1, 'cancelled') } });

        const store = useTasksStore();
        await store.fetchForProject(1);
        store.selected = store.tasks[0];

        await store.changeStatus(store.tasks[0], 'cancelled');

        expect(store.tasks[0].status).toBe('cancelled');
        expect(store.selected?.status).toBe('cancelled');
    });

    it('專案統計由清單算出來，不是寫死的數字', async () => {
        get.mockResolvedValue({
            data: {
                data: [
                    { id: 1, name: 'A', status: 'active' },
                    { id: 2, name: 'B', status: 'active' },
                    { id: 3, name: 'C', status: 'failed' },
                ],
            },
        });

        const store = useProjectsStore();
        await store.fetchAll();

        expect(store.countByStatus).toEqual({ active: 2, failed: 1 });
    });
});
