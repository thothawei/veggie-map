/**
 * AI Office 的 API 型別。刻意跟餐廳領域的 `@/types` 分開放：兩邊除了
 * `ApiSuccess` 沒有共用概念，混在同一個檔案只會讓兩邊互相污染。
 *
 * 欄位名一律照後端 Resource 的 snake_case，不在前端改名——改名之後要對照
 * 一份翻譯表才知道哪個欄位對哪個，除錯成本比省下的底線高。
 */

export const PROJECT_STATUSES = ['planning', 'active', 'paused', 'completed', 'failed', 'archived'] as const;
export type ProjectStatus = (typeof PROJECT_STATUSES)[number];

export const TASK_STATUSES = [
    'pending', 'planning', 'assigned', 'running', 'waiting_review',
    'approved', 'rejected', 'completed', 'failed', 'cancelled',
] as const;
export type TaskStatus = (typeof TASK_STATUSES)[number];

export const AGENT_STATUSES = ['idle', 'working', 'waiting_review', 'error', 'offline'] as const;
export type AgentStatus = (typeof AGENT_STATUSES)[number];

export interface AiOfficeProject {
    id: number;
    name: string;
    description: string | null;
    repository_url: string | null;
    workspace_path: string | null;
    status: ProjectStatus;
    created_by: number | null;
    task_count?: number;
    created_at: string | null;
    updated_at: string | null;
}

export interface AiOfficeAgent {
    id: number;
    name: string;
    role: string;
    avatar: string | null;
    description: string | null;
    status: AgentStatus;
    model_provider: string;
    model_name: string;
    max_concurrency: number;
    /** 只有 `GET /agents/{id}` 的詳細模式才有。 */
    system_prompt?: string;
    tools?: string[];
    permissions?: Record<string, string>;
    active_task_count?: number;
}

export interface AiOfficeTask {
    id: number;
    project_id: number;
    parent_task_id: number | null;
    title: string;
    description: string | null;
    status: TaskStatus;
    priority: number;
    assigned_agent_id: number | null;
    agent?: AiOfficeAgent;
    result: Record<string, unknown> | null;
    error: string | null;
    retry_count: number;
    max_retries: number;
    dependencies?: number[];
    dependencies_satisfied?: boolean;
    started_at: string | null;
    completed_at: string | null;
    created_at: string | null;
    updated_at: string | null;
}

export interface AiOfficeApproval {
    id: number;
    project_id: number | null;
    task_id: number | null;
    agent_id: number | null;
    tool_execution_id: number | null;
    action: string;
    risk_level: 'low' | 'medium' | 'high' | 'critical';
    reason: string | null;
    payload: Record<string, unknown> | null;
    status: 'pending' | 'approved' | 'rejected' | 'expired';
    approved_by: number | null;
    approved_at: string | null;
    rejected_by: number | null;
    rejected_at: string | null;
    expires_at: string | null;
    created_at: string | null;
}

export interface AiOfficeActivity {
    id: number;
    project_id: number | null;
    task_id: number | null;
    agent_id: number | null;
    type: string;
    description: string;
    payload: Record<string, unknown> | null;
    created_at: string | null;
}

export interface StreamTicket {
    ticket: string;
    expires_in: number;
    latest_id: number;
}

export interface UsageTotals {
    requests: number;
    input_tokens: number;
    output_tokens: number;
    total_tokens: number;
    /** 金額是字串（後端固定 6 位小數）：帳務數字不經過浮點數。 */
    estimated_cost: string;
}

export interface UsageGroupRow {
    requests: number;
    total_tokens: number;
    estimated_cost: string;
    model?: string;
    agent_id?: number | null;
    agent_name?: string | null;
    project_id?: number | null;
    project_name?: string | null;
}

export interface UsageDailyRow {
    day: string;
    total_tokens: number;
    estimated_cost: string;
}

export interface UsageReport {
    totals: UsageTotals;
    by_model: UsageGroupRow[];
    by_agent: UsageGroupRow[];
    by_project: UsageGroupRow[];
    daily: UsageDailyRow[];
}

export interface AgentPerformance {
    agent_id: number;
    name: string;
    role: string;
    status: AgentStatus;
    tasks: number;
    completed: number;
    failed: number;
    retries: number;
    runs: number;
    /** 沒接過任務時是 null，不是 0——兩者意義不同。 */
    success_rate: number | null;
    avg_duration_ms: number | null;
    total_tokens: number;
    estimated_cost: string;
}

export const MEMORY_TYPES = [
    'project_context', 'technical_decision', 'user_preference', 'task_result', 'error_pattern',
] as const;
export type MemoryType = (typeof MEMORY_TYPES)[number];

export interface AgentMemoryItem {
    id: number;
    agent_id: number;
    project_id: number | null;
    memory_type: MemoryType;
    content: string;
    importance: number;
    created_at: string | null;
}

/** GET /ai-office/dashboard（規格第 38／50 節）。 */
export interface AiOfficeDashboard {
    today: { completed: number; waiting: number; errors: number; running: number };
    agents: Record<string, number>;
    projects: Record<string, number>;
    approvals: { pending: number };
}

/** 規格第 34 節：Agent 之間的訊息。 */
export interface AiOfficeMessageParty {
    id: number;
    name: string;
    role: string;
}

export interface AiOfficeMessage {
    id: number;
    task_id: number | null;
    content: string;
    from: AiOfficeMessageParty | null;
    to: AiOfficeMessageParty | null;
    created_at: string;
}
