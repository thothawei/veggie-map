import type { AgentStatus, ProjectStatus, TaskStatus } from './types';

/**
 * 狀態的中文顯示名。狀態碼本身不翻譯（API 傳的是 `running`，畫面上寫「執行中」），
 * 對照表放這裡一份，元件不要各寫各的——同一個狀態在兩個畫面叫不同名字最難除錯。
 */
export const TASK_STATUS_LABELS: Record<TaskStatus, string> = {
    pending: '待處理',
    planning: '規劃中',
    assigned: '已指派',
    running: '執行中',
    waiting_review: '等待核准',
    approved: '已核准',
    rejected: '已拒絕',
    completed: '已完成',
    failed: '失敗',
    cancelled: '已取消',
};

export const PROJECT_STATUS_LABELS: Record<ProjectStatus, string> = {
    planning: '規劃中',
    active: '進行中',
    paused: '暫停',
    completed: '已完成',
    failed: '失敗',
    archived: '已封存',
};

export const AGENT_STATUS_LABELS: Record<AgentStatus, string> = {
    idle: '待命',
    working: '工作中',
    waiting_review: '等待核准',
    error: '錯誤',
    offline: '離線',
};

export const RISK_LABELS: Record<string, string> = {
    low: '低風險',
    medium: '中風險',
    high: '高風險',
    critical: '極高風險',
};

/** ISO 字串 → `08/25 17:42`。事件流是「剛剛發生什麼」，年份沒有資訊量。 */
export function formatEventTime(iso: string | null): string {
    if (!iso) {
        return '';
    }

    const date = new Date(iso);

    if (Number.isNaN(date.getTime())) {
        return '';
    }

    const pad = (value: number) => String(value).padStart(2, '0');

    return `${pad(date.getMonth() + 1)}/${pad(date.getDate())} ${pad(date.getHours())}:${pad(date.getMinutes())}`;
}
