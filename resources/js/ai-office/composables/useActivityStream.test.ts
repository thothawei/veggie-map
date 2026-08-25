import { beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises } from '@vue/test-utils';

const listActivities = vi.fn();
const requestTicket = vi.fn();

vi.mock('../api/events', () => ({
    listActivities: (...args: unknown[]) => listActivities(...args),
    requestTicket: (...args: unknown[]) => requestTicket(...args),
    streamUrl: (projectId: number, ticket: string, afterId: number) =>
        `/api/v1/ai-office/projects/${projectId}/events?ticket=${ticket}&after_id=${afterId}`,
}));

/** jsdom 沒有 EventSource，測試自己給一個可以手動觸發事件的替身。 */
class FakeEventSource {
    static instances: FakeEventSource[] = [];

    onerror: (() => void) | null = null;

    closed = false;

    private listeners: Record<string, ((event: MessageEvent) => void)[]> = {};

    constructor(public url: string) {
        FakeEventSource.instances.push(this);
    }

    addEventListener(type: string, handler: (event: MessageEvent) => void): void {
        (this.listeners[type] ??= []).push(handler);
    }

    emit(type: string, data?: unknown): void {
        (this.listeners[type] ?? []).forEach((handler) =>
            handler({ data: JSON.stringify(data ?? {}) } as MessageEvent));
    }

    close(): void {
        this.closed = true;
    }
}

const { useActivityStream } = await import('./useActivityStream');

function activity(id: number, type = 'TaskStarted') {
    return { id, project_id: 1, task_id: null, agent_id: null, type, description: `事件 ${id}`, payload: null, created_at: null };
}

describe('useActivityStream', () => {
    beforeEach(() => {
        vi.stubGlobal('EventSource', FakeEventSource);
        FakeEventSource.instances = [];
        listActivities.mockReset();
        requestTicket.mockReset();
        listActivities.mockResolvedValue({ activities: [activity(2), activity(1)], latestId: 2 });
        requestTicket.mockResolvedValue({ ticket: 'tkt', expires_in: 60, latest_id: 2 });
    });

    it('先用 REST 補齊歷史，再帶著游標開串流', async () => {
        const stream = useActivityStream();
        stream.start(1);
        await flushPromises();

        expect(stream.activities.value.map((item) => item.id)).toEqual([2, 1]);
        // 游標用 meta.latest_id，不是清單第一筆——清單會被 per_page 截斷。
        expect(FakeEventSource.instances[0].url).toContain('after_id=2');
        expect(FakeEventSource.instances[0].url).toContain('ticket=tkt');
    });

    it('收到 activity 事件就插到最前面，重複的 id 不會出現兩次', async () => {
        const stream = useActivityStream();
        stream.start(1);
        await flushPromises();

        FakeEventSource.instances[0].emit('activity', activity(3));
        FakeEventSource.instances[0].emit('activity', activity(3));

        expect(stream.activities.value.map((item) => item.id)).toEqual([3, 2, 1]);
        expect(stream.state.value).toBe('live');
    });

    it('reconnect 事件會關掉舊連線、換新票、從 last_id 續接', async () => {
        vi.useFakeTimers();
        const stream = useActivityStream();
        stream.start(1);
        await vi.runAllTimersAsync();

        listActivities.mockResolvedValue({ activities: [], latestId: 9 });
        FakeEventSource.instances[0].emit('reconnect', { last_id: 9 });
        await vi.runAllTimersAsync();

        expect(FakeEventSource.instances[0].closed).toBe(true);
        expect(FakeEventSource.instances).toHaveLength(2);
        expect(FakeEventSource.instances[1].url).toContain('after_id=9');
        // 重連前一定要再對帳一次，否則斷線視窗裡的事件永遠補不回來。
        expect(listActivities).toHaveBeenCalledTimes(2);
        vi.useRealTimers();
    });

    it('連線錯誤時自己關掉並排程重連，不靠瀏覽器自動重連', async () => {
        vi.useFakeTimers();
        const stream = useActivityStream(1000);
        stream.start(1);
        await vi.runAllTimersAsync();

        FakeEventSource.instances[0].onerror?.();

        expect(FakeEventSource.instances[0].closed).toBe(true);
        expect(stream.state.value).toBe('connecting');

        await vi.advanceTimersByTimeAsync(1000);
        await flushPromises();

        // 票是一次性的，重連一定要換新的一張。
        expect(requestTicket).toHaveBeenCalledTimes(2);
        expect(FakeEventSource.instances).toHaveLength(2);
        vi.useRealTimers();
    });

    it('沒有 EventSource 的環境退回輪詢，而不是停在連線中', async () => {
        vi.stubGlobal('EventSource', undefined);
        const stream = useActivityStream();
        stream.start(1);
        await flushPromises();

        expect(stream.state.value).toBe('polling');
        expect(requestTicket).not.toHaveBeenCalled();
    });

    it('換不到票時退回輪詢並說明原因', async () => {
        requestTicket.mockRejectedValue(new Error('403'));
        const stream = useActivityStream();
        stream.start(1);
        await flushPromises();

        expect(stream.state.value).toBe('polling');
        expect(stream.error.value).toBe('無法建立即時連線，改用輪詢');
    });

    it('stop 會關掉連線，之後的重連排程也不會再開新的', async () => {
        vi.useFakeTimers();
        const stream = useActivityStream(1000);
        stream.start(1);
        await vi.runAllTimersAsync();

        stream.stop();

        expect(FakeEventSource.instances[0].closed).toBe(true);
        expect(stream.state.value).toBe('stopped');

        await vi.advanceTimersByTimeAsync(5000);
        expect(FakeEventSource.instances).toHaveLength(1);
        vi.useRealTimers();
    });
});
