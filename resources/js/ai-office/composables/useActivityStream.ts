import { ref, shallowRef } from 'vue';
import { listActivities, requestTicket, streamUrl } from '../api/events';
import type { AiOfficeActivity } from '../types';

export type StreamState = 'idle' | 'connecting' | 'live' | 'polling' | 'stopped';

/**
 * 訂閱一個專案的事件流（後端 SSE 見 docs/api.md）。
 *
 * 三件事情是這裡的重點，缺一個都會讓畫面「看起來在動、其實少了東西」：
 *
 * 1. **每次連上線都先用 REST 對帳**。SSE 只送連線期間發生的事件，斷線視窗裡的那些
 *    沒有人會補；所以連線前先把 `after_id` 之後的事件抓回來，再開串流。
 * 2. **收到 error 就自己關掉重來，不靠瀏覽器自動重連**。票是一次性的，瀏覽器拿同一
 *    張票重連只會一路 401，而且它不會停——會變成穩定的錯誤迴圈。
 * 3. **沒有 EventSource 就退回輪詢**。不是每個環境都有（測試的 jsdom 就沒有），
 *    退回輪詢至少資料是對的，比整頁不更新好。
 */
export function useActivityStream(pollIntervalMs = 5000) {
    const activities = ref<AiOfficeActivity[]>([]);
    const state = ref<StreamState>('idle');
    const error = ref<string | null>(null);
    const source = shallowRef<EventSource | null>(null);

    let projectId: number | null = null;
    let lastId = 0;
    let timer: ReturnType<typeof setTimeout> | null = null;
    let stopped = true;
    // 退回輪詢的原因。輪詢成功時只清掉「這次抓失敗」的訊息，不能連降級原因一起清掉
    // ——那會讓畫面顯示「輪詢中」卻不說為什麼，看起來像即時其實不是。
    let degradedReason: string | null = null;

    function push(activity: AiOfficeActivity): void {
        // 對帳跟串流可能送來同一筆（游標邊界），用 id 去重而不是靠順序。
        if (activities.value.some((item) => item.id === activity.id)) {
            return;
        }

        activities.value = [activity, ...activities.value].slice(0, 200);
        lastId = Math.max(lastId, activity.id);
    }

    async function catchUp(): Promise<void> {
        const result = await listActivities(projectId as number, lastId || undefined);

        if (lastId === 0) {
            // 第一次：後端回的是由新到舊，直接當清單；游標用 meta.latest_id，
            // 不用清單第一筆——清單可能被 per_page 截斷。
            activities.value = result.activities;
            lastId = result.latestId;

            return;
        }

        result.activities.forEach(push);
    }

    function clearTimer(): void {
        if (timer !== null) {
            clearTimeout(timer);
            timer = null;
        }
    }

    function scheduleRetry(delayMs: number): void {
        clearTimer();
        timer = setTimeout(() => {
            void connect();
        }, delayMs);
    }

    function closeSource(): void {
        source.value?.close();
        source.value = null;
    }

    async function poll(): Promise<void> {
        if (stopped) {
            return;
        }

        try {
            await catchUp();
            error.value = degradedReason;
        } catch {
            error.value = '取得事件失敗，稍後重試';
        }

        clearTimer();
        timer = setTimeout(() => {
            void poll();
        }, pollIntervalMs);
    }

    async function connect(): Promise<void> {
        if (stopped || projectId === null) {
            return;
        }

        state.value = 'connecting';

        try {
            await catchUp();
        } catch {
            error.value = '取得事件失敗，稍後重試';
            scheduleRetry(pollIntervalMs);

            return;
        }

        if (typeof EventSource === 'undefined') {
            state.value = 'polling';
            void poll();

            return;
        }

        let ticket: string;

        try {
            ticket = (await requestTicket(projectId)).ticket;
        } catch {
            // 換不到票（多半是權限或連線問題）——退回輪詢，不要讓畫面停在「連線中」。
            degradedReason = '無法建立即時連線，改用輪詢';
            error.value = degradedReason;
            state.value = 'polling';
            void poll();

            return;
        }

        if (stopped) {
            return;
        }

        const es = new EventSource(streamUrl(projectId, ticket, lastId));
        source.value = es;

        es.addEventListener('activity', (event) => {
            push(JSON.parse((event as MessageEvent).data) as AiOfficeActivity);
            state.value = 'live';
            error.value = null;
        });

        es.addEventListener('heartbeat', () => {
            state.value = 'live';
            error.value = null;
        });

        // 後端連線壽命到期時會主動送這個再關掉，帶著游標重連即可，不算錯誤。
        es.addEventListener('reconnect', (event) => {
            const data = JSON.parse((event as MessageEvent).data) as { last_id: number };
            lastId = Math.max(lastId, data.last_id);
            closeSource();
            scheduleRetry(0);
        });

        es.onerror = () => {
            closeSource();
            state.value = 'connecting';
            scheduleRetry(pollIntervalMs);
        };
    }

    function start(id: number): void {
        stop();
        stopped = false;
        degradedReason = null;
        error.value = null;
        projectId = id;
        activities.value = [];
        lastId = 0;
        void connect();
    }

    function stop(): void {
        stopped = true;
        clearTimer();
        closeSource();
        state.value = 'stopped';
    }

    return { activities, state, error, start, stop };
}
