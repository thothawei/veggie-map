# AI Office 實作計畫（整合進 VeggieMap repo）

> 來源規格：`AI Office — Claude Code 完整專案開發規格 Prompt`（以下簡稱「規格」，引用時標節號）。
> 本文件對應規格 §81「第一個執行指令」的產出：先盤點、先定架構、先標衝突，**不改 production 檔案**。
> 決策紀錄：2026-08-25 與使用者確認 → **在 veggie-map repo 內加 AI Office 子系統**，
> 計畫寫完直接接 Phase 1。

---

## 1. Current Repository Analysis（現況盤點）

這**不是**空專案。VeggieMap 是一個已完成 Phase 0～13 主線、可運作的 Laravel + Vue 專案。

| 項目 | 現況 | 證據 |
| --- | --- | --- |
| Framework | Laravel 11.31 | `composer.json` |
| PHP | `^8.2`（容器內；本機 CLI 是 8.1，**測試一律在容器裡跑**） | `composer.json` / `php -v` |
| DB | **MySQL 8**，重度使用 `POINT` / `ST_Distance_Sphere` / `MBRContains` | `phpunit.xml` 註解、`RestaurantRepository` |
| Cache / Queue | Redis + Laravel Horizon | `.env.example`、`docker-compose.yml` |
| Auth | Laravel Sanctum，`users.role` enum(`user`,`admin`) | `AuthController`、`add_role_to_users_table` |
| API | REST，前綴 `/api/v1`，統一 `{success, data, meta}` 回應 | `bootstrap/app.php`、`LookupController` |
| 例外處理 | `ApiExceptionRenderer` 統一渲染，`error.code` 格式 | `bootstrap/app.php` |
| 限流 | `RateLimiter::for('api')`，Redis 後端，60/min | `AppServiceProvider::boot()` |
| 前端 | **Vue 3 + Pinia + Vue Router + Leaflet**，由 `laravel-vite-plugin` 承載（非分離 SPA） | `vite.config.js`、`resources/js/` |
| 測試 | PHPUnit 11（非 Pest），28 個測試檔 | `phpunit.xml`、`tests/` |
| 靜態檢查 | Larastan(PHPStan) + Pint；前端 ESLint + vue-tsc + Vitest | `phpstan.neon`、`package.json` |
| Docker | nginx / app / horizon / scheduler / mysql / redis | `docker-compose.yml` |
| CI | `.github/` 已存在 | — |

**基準測試（2026-08-25 實測）**：容器內 `php artisan test` → **199 passed / 0 failed / 586 assertions / 39s**。

> ⚠️ 已知環境坑（實測重現）：`veggiemap_testing` 在測試開頭會被 `migrate:fresh` 清空約 3 秒，
> 這段空窗內任何其他連線（另一個 session 的測試、手動查詢）都會拿到
> `Base table or view not found`。本次盤點時就先後看到 10／171／21／27 個假失敗，
> 單獨重跑全綠。**判準：不要把這種錯誤當成程式碼 bug，先確認沒有第二個測試程序在跑。**

---

## 2. Conflicts with the Spec（與規格的衝突與裁決）

規格假設是全新專案。整合進既有 repo 後有三個硬衝突，裁決如下，**這些是刻意偏離，不是遺漏**：

| # | 規格要求 | 本專案決定 | 理由 |
| --- | --- | --- | --- |
| C1 | PostgreSQL（§1、§61） | **沿用 MySQL 8** | 餐廳查詢建立在 MySQL 空間函式與空間索引上，換 DB 要重寫 `RestaurantRepository` 與全部 18 張 migration，且與 AI Office 功能零關係。規格 §41 只要求「第一版用關聯式資料庫、不要上 Vector DB」，MySQL 完全滿足。未來若真要 pgvector，屆時是獨立遷移議題。 |
| C2 | React + TypeScript + Zustand + TanStack Query（§2、§44） | **沿用 Vue 3 + Pinia + Vue Router** | 同一 codebase 塞兩套框架 = 兩套 build、兩套測試、兩套型別。規格 §44 的元件清單會 1:1 對應成 Vue 元件（見 §6 Frontend Plan），資訊架構完全照做，只換框架。 |
| C3 | 前後端分離、`ai-office/` 獨立目錄（§2、§65） | **同 repo、以命名空間隔離** | 使用者選擇「在 veggie-map repo 內加子系統」。改用 `App\AiOffice\*` 命名空間 + `resources/js/ai-office/*` + `/api/v1/ai-office/*` 路由前綴做邏輯隔離。 |
| C4 | Pest（§56） | **沿用 PHPUnit 11** | 既有 28 個測試檔都是 PHPUnit，混用兩套 runner 沒有收益。 |
| C5 | `users.role` 只有 `user`/`admin` | **擴充 enum 加 `manager`/`developer`/`viewer`** | 規格 §52/§53 要四種角色。保留既有 `user`（一般消費者，只用地圖）與 `admin`（等同規格的 admin）。 |

另外兩點規格已自己留了退路，照做即可：
- §39 Resource Monitoring：拿不到 host metrics 就用 application-level metrics，且 **UI 必須標示資料來源**。
- §41 Memory：第一版用關聯式 DB，不上 Vector DB。

---

## 3. Architecture（架構）

```text
┌─────────────────────────── VeggieMap repo ───────────────────────────┐
│                                                                       │
│  既有領域（不動）                    AI Office 子系統（新增）           │
│  ─────────────                     ──────────────────────            │
│  App\Models\Restaurant             App\AiOffice\Models\{Project,      │
│  App\Services\ReviewService          Agent,Task,Approval,...}         │
│  App\Repositories\...              App\AiOffice\Runtime\AgentRuntime  │
│  /api/v1/restaurants               App\AiOffice\Llm\{LlmProvider,     │
│  resources/js/views/HomeView         ClaudeProvider,MockProvider}     │
│                                    App\AiOffice\Tools\{File,Git,      │
│                                      Terminal,Docker,Database}Tool    │
│                                    App\AiOffice\Jobs\*               │
│                                    /api/v1/ai-office/*               │
│                                    resources/js/ai-office/*          │
│                                                                       │
│  共用：Sanctum 認證、ApiExceptionRenderer、RateLimiter、Redis、        │
│        Horizon、MySQL、Docker Compose、Vite、PHPUnit                  │
└───────────────────────────────────────────────────────────────────────┘
```

執行流程（規格 §0）在本專案的落點：

```text
User → POST /api/v1/ai-office/projects        （Controller，同步只建 Project）
     → PlanProjectJob（Redis queue / Horizon）
     → CeoPlanner + LlmProvider → structured JSON（schema 驗證，§28）
     → 建立 Task + TaskDependency（DAG，防環，§10）
     → AgentSelector 指派（§29）
     → ExecuteTaskJob → AgentRuntime loop（§25、§26）
     → Tool 呼叫（權限檢查 → 需要 Approval 就暫停，§21～§24）
     → task_runs 記錄每次執行（§14）+ token_usages（§40）
     → QA Agent → 失敗則開 Bug Task 回派（§33）
     → Approval 通過 → 完成
     → 全程寫 activities，前端用 SSE 訂閱（§35、§36）
```

---

## 4. Technology Versions（版本，不降級、不無故升 major）

沿用現有版本，AI Office 不新增 major 相依。預期只需新增：

- 後端：**`anthropic-ai/sdk`（官方 Anthropic PHP SDK）**。原本計畫寫的是「用 Laravel 內建
  `Http` client 自己打、不引入 SDK」，Phase 3 實作時改變決定：request/response 的欄位命名、
  thinking 區塊、tool_use 區塊的形狀都會隨 API 演進，自己拼 JSON 等於把這些變動的維護責任
  攬到本專案身上。SDK 的 camelCase 具名參數會自動對應到線上的 snake_case。
  安裝的是 `^0.43.0`，未新增其他相依。
- 前端：沿用 axios / Pinia。DAG 視覺化第一版用純 SVG 手繪（規格 §49「不需要非常複雜」），不引入 d3。

任何要新增套件的時刻，先確認與 Laravel 11 / PHP 8.2 / Vite 6 相容才動 lock file。

---

## 5. Database Plan

規格 §11 的 17 張表，扣掉已存在的 `users`，其餘 16 張全部新增，一律加 `ai_office_` 前綴避免與
餐廳領域撞名（例如 `reviews`、`messages` 這種通用字）。

| 規格表名 | 本專案表名 | 備註 |
| --- | --- | --- |
| users | `users`（沿用） | 只擴充 role enum |
| projects | `ai_office_projects` | §8 欄位 |
| agents | `ai_office_agents` | §12 欄位 |
| agent_tools | `ai_office_agent_tools` | agent ↔ tool 多對多 |
| agent_permissions | `ai_office_agent_permissions` | §21，值為 `allow`/`deny`/`approval` |
| agent_memories | `ai_office_agent_memories` | §41，`content` 用 JSON，保留未來 pgvector |
| tasks | `ai_office_tasks` | §13 欄位，自參照 `parent_task_id` |
| task_dependencies | `ai_office_task_dependencies` | §10，(task_id, depends_on_task_id) 唯一 |
| task_assignments | `ai_office_task_assignments` | 指派歷程（可重派） |
| task_runs | `ai_office_task_runs` | §14，每次執行一列 |
| tool_executions | `ai_office_tool_executions` | 每次工具呼叫一列 |
| approvals | `ai_office_approvals` | §23 |
| messages | `ai_office_messages` | §34 agent 間溝通 |
| activities | `ai_office_activities` | §35 事件流，SSE 來源 |
| project_files | `ai_office_project_files` | workspace 產出檔案索引 |
| token_usages | `ai_office_token_usages` | §40 |
| agent_errors | `ai_office_agent_errors` | §32 |

索引原則：所有 `*_id` 外鍵建索引；`activities` 加 `(project_id, id)` 供 SSE 增量拉取；
`token_usages` 加 `(created_at)` 供日/週/月統計；`tasks` 加 `(project_id, status)`。

---

## 6. Backend Plan

命名空間 `App\AiOffice\`（PSR-4 已涵蓋 `App\` → `app/`，不用改 composer.json）。

```text
app/AiOffice/
├── Models/            Project, Agent, Task, TaskDependency, TaskRun,
│                      ToolExecution, Approval, Message, Activity,
│                      AgentMemory, TokenUsage, AgentError, ProjectFile
├── Llm/               LlmProviderInterface, ClaudeProvider, MockProvider,
│                      LlmRequest, LlmResponse, ToolCall
├── Runtime/           AgentRuntime, AgentLoopGuard（步數/工具數/token 上限）
├── Orchestration/     AgentOrchestrator, CeoPlanner, PlanSchema, AgentSelector,
│                      TaskGraph（拓撲排序 + 環偵測）
├── Tools/             ToolInterface, ToolRegistry, FileTool, GitTool,
│                      TerminalTool, DockerTool, DatabaseTool
├── Security/          PermissionGate, RiskLevel, WorkspaceGuard,
│                      CommandAllowlist, SandboxManager
├── Services/          TokenUsageService, ActivityRecorder, ApprovalService,
│                      DashboardStatsService, ResourceMetricsService
├── Jobs/              PlanProjectJob, ExecuteTaskJob, RunAgentJob, RunQaJob,
│                      RetryFailedTaskJob, ProcessApprovalJob, DeployJob
└── Http/              Controllers/, Requests/, Resources/
```

API（規格 §50）掛在 `/api/v1/ai-office/*`，沿用既有 `throttle:api` + `auth:sanctum`，
回應沿用既有 `{success, data, meta}`（規格 §51 的格式與現行慣例一致，不需要另立一套）。

Queue：沿用 Redis + Horizon，新增 `ai-office` 佇列，`config/horizon.php` 加對應 supervisor。
**HTTP request 內不得同步跑 LLM**（規格 §30）。

---

## 7. Frontend Plan

`resources/js/ai-office/`，路由前綴 `/ai-office`，掛在既有 Vue Router 上。
規格 §44 的元件清單 1:1 轉成 Vue SFC：

```text
resources/js/ai-office/
├── components/
│   ├── office/     OfficeMap, OfficeRoom, AgentCharacter, AgentDesk, AgentStatusBadge
│   ├── dashboard/  CommandCenter, ActivityFeed, Statistics, ResourceUsage, ApprovalPanel
│   ├── task/       TaskBoard, TaskCard, TaskDetail, TaskGraph
│   └── agent/      AgentList, AgentCard, AgentDetail
├── views/          DashboardView, ProjectsView, ProjectDetailView, TasksView,
│                   AgentsView, AgentDetailView, LogsView, ApprovalsView, UsageView
├── stores/         agent, project, task, activity, ui（Pinia，非 Zustand）
└── api/            projects.ts, agents.ts, tasks.ts, approvals.ts, events.ts
```

- 即時更新用 `EventSource` 訂閱 `/api/v1/ai-office/projects/{id}/events`（規格 §36）。
- Pixel Office 用 CSS + SVG，不用點陣圖（規格 §45）。
- **所有狀態與統計數字來自 API，禁止 hardcode**（規格 §7、§38、§74）。
- 配色照規格 §69（`#0B0F14` / `#111820` / `#26313D`），用 Tailwind theme 變數擴充，
  不覆蓋餐廳地圖現有樣式。

---

## 8. Agent Architecture

初始 7 個 Agent（規格 §6 六個 + §67 seeder 提到的「設計小花」）：

| name | role | 主要 Tools |
| --- | --- | --- |
| AI 主管 Michael | ceo | 無（只規劃／派工／驗收） |
| 前端小王 | frontend | file, git(diff/commit) |
| 後端阿明 | backend | file, git(diff/commit), database(read) |
| 自動化小林 | automation | file, terminal(allowlist) |
| 測試小美 | qa | file, terminal(測試指令), git(diff) |
| 設計小花 | design | file |
| 維運小陳 | devops | docker, git(push), deploy(需 Approval) |

Agent status（規格 §7）：`idle` / `working` / `waiting_review` / `error` / `offline`，
由 AgentRuntime 真實寫入，前端只讀。

AgentRuntime 上限（規格 §26，寫在 `config/ai_office.php`，不寫死）：
`MAX_AGENT_STEPS` / `MAX_TOOL_CALLS` / `MAX_RETRIES`(=3) / `MAX_TOKEN_BUDGET`。

---

## 9. Tool Architecture & Security Plan

Risk level（規格 §22）：`low` / `medium` / `high` / `critical`。
權限判定順序：**Agent 權限（allow/deny/approval）→ Tool risk → 全域 policy**，
任一層 deny 就拒絕；判定為 approval 就開 `ai_office_approvals` 並暫停 Task。

| Tool | 動作 | Risk |
| --- | --- | --- |
| FileTool | read_file / list_files / search_files | low |
| FileTool | write_file | medium |
| GitTool | git_status / git_diff / git_log / git_branch | low |
| GitTool | git_add / git_checkout / git_commit | medium |
| GitTool | git_push | high |
| TerminalTool | execute_command（allowlist 內） | medium |
| DockerTool | docker_build / docker_run / docker_logs / docker_stop | medium |
| DatabaseTool | SELECT / EXPLAIN / DESCRIBE | low |
| Deploy | deploy_staging | high |
| Deploy | deploy_production | critical（**一定要 Approval**） |

硬邊界（規格 §16、§18～§20、§42、§43、§54）：
- **WorkspaceGuard**：所有檔案路徑 `realpath()` 後必須落在 `workspace/{project_id}/` 內，
  拒絕 symlink 逃逸與 `..`；跨 Project 存取一律拒絕。
- **CommandAllowlist**：TerminalTool 只放行白名單指令；`rm -rf /`、`shutdown`、`reboot`、
  讀取 SSH 私鑰、讀其他 project workspace 一律 denylist 硬擋。
- **DatabaseTool**：只允許 `SELECT`/`EXPLAIN`/`DESCRIBE`，用語法前綴 + 關鍵字雙重檢查；
  production 連線完全禁止。
- **SandboxManager**（Phase 11 已實作）：Terminal / Docker 執行走容器，限制 CPU / memory /
  timeout / network，另加 `--cap-drop ALL`、`no-new-privileges`、唯讀 rootfs、非 root 使用者、
  pids 上限；只掛專案 workspace，不掛 docker.sock。docker 不可用時
  `SANDBOX_ENABLED=true` 時 TerminalTool **直接拒絕執行**而不是退回
  在 host 上跑——寧可功能缺席，不可假裝安全。
- **Secret**：`ANTHROPIC_API_KEY` 只從 `.env` 讀，不入 DB、不入 log；
  LLM request/response 寫 log 前先過遮罩。
- Agent 不得直接 push `main`（規格 §62）。

---

## 10. Testing Plan

後端（PHPUnit，容器內跑）：
`AiOffice/` 測試目錄，涵蓋規格 §56 全部項目——認證、Project/Task CRUD、
**TaskDependency 環偵測**、AgentSelector、Queue 派發、AgentRuntime（用 MockProvider）、
Tool 權限、WorkspaceGuard 逃逸、CommandAllowlist、Approval 流程、Retry、TokenUsage、SSE。

前端（Vitest）：AgentCard、TaskCard、ApprovalModal、TaskGraph、CommandCenter。

整合測試（規格 §57）：`MockLlmProvider` 驅動 User → CEO → Planner → Tasks → Backend →
QA → Completed 全流程，**不打真的 Claude API**。

反向驗證原則（沿用本 repo 既有慣例）：安全類測試要能證明「把防線拿掉測試會紅」，
否則只是永遠 PASS 的假斷言。

---

## 11. Docker Plan

現有 compose 已有 nginx / app / horizon / scheduler / mysql / redis，**全部沿用**。
AI Office 需要的增量：

- Horizon 增加 `ai-office` 佇列的 supervisor（改 `config/horizon.php`，不加新容器）。
- Phase 11 沒有加常駐的 `agent-sandbox` 容器：沙箱是**每次執行開一個 `--rm` 的短命容器**，
  不是一台一直開著的機器。要跑起來需要 app container 看得到 docker socket，用
  `docker-compose.sandbox.yml` 明確加購（權衡寫在該檔案檔頭）。
- 不新增 postgres 容器（見 C1）。

---

## 12. Phase Plan（對照規格 §72，依本 repo 現況重排）

| Phase | 規格對應 | 本專案內容 | 狀態 |
| --- | --- | --- | --- |
| 1 | §72 P1 | 基礎設施驗證（Laravel/MySQL/Redis/Docker/Sanctum 皆已存在）、`config/ai_office.php` 設定骨架、`.env.example` 補鍵、RBAC 四角色擴充、真實 health/readiness 端點 + 測試 | ✅ 完成 |
| 2 | §72 P2 | 16 張 migration + 16 個 Model + Project/Task CRUD + Agent 唯讀 + TaskDependency（含環偵測）+ 初始 Agent seeder | ✅ 完成 |
| 3 | §72 P3 | LlmProviderInterface / ClaudeProvider / MockProvider / AgentRuntime + AgentLoopGuard + PermissionGate + TokenUsageService（全程用 Mock 驗，未呼叫真 API） | ✅ 完成 |
| 4 | §72 P4 | AgentOrchestrator / CeoPlanner（JSON schema 驗證）/ AgentSelector / Queue / Retry | ✅ 完成 |
| 5 | §72 P5 | 五個 Tool + PermissionGate + WorkspaceGuard + CommandAllowlist | ✅ 完成 |
| 6 | §72 P6 | Approval / RiskLevel / human-in-the-loop | ✅ 完成 |
| 7 | §72 P7 | Activity + SSE + Agent/Task 狀態即時推送 | ✅ 完成 |
| 8 | §72 P8 | Vue Dashboard：CommandCenter / AgentCard / TaskBoard / TaskDetail / ApprovalPanel / Usage | ✅ 完成 |
| 9 | §72 P9 | Pixel Office（CSS + SVG） | ✅ 完成 |
| 10 | §72 P10 | TokenUsage / Cost / AgentMemory / Agent 效能統計 | ✅ 完成 |
| 11 | §72 P11 | Docker Sandbox / GitHub Actions CI | ✅ 完成 |
| 12 | §72 P12 | 完整 Demo（規格 §79 的 Todo API 情境） | ✅ 完成 |

每個 Phase 走 `Inspect → Plan → Implement → Test → Fix → Verify → Document`，
完成後照規格 §75 回報 PHASE STATUS，才進下一階段。

---

## 13. Risks（風險與對策）

| 風險 | 影響 | 對策 |
| --- | --- | --- |
| **測試庫空窗期假失敗** | 誤判成程式碼 bug、浪費一輪除錯 | 已寫進 §1。跑測試前先確認沒有第二個測試程序；紅了先單獨重跑再下判斷 |
| Agent 逃出 workspace | 讀到 SSH 金鑰、改到 host | WorkspaceGuard + `realpath()` 邊界檢查 + 專門的逃逸測試 |
| TerminalTool 沙箱未就緒就上線 | 等於把 host shell 交給 LLM | 沙箱沒開就**拒絕執行**，不退回 host 跑 |
| Agent loop 燒 token / 無限迴圈 | 帳單爆炸 | `MAX_AGENT_STEPS` / `MAX_TOOL_CALLS` / `MAX_TOKEN_BUDGET` 硬上限 + `token_usages` 記帳 |
| 循環相依（A→B→A） | 排程死結 | 建立 dependency 時做 DFS 環偵測，直接 422 拒絕 |
| 子系統污染餐廳領域 | 既有功能回歸 | 命名空間 / 表前綴 / 路由前綴三層隔離；每個 Phase 都要跑**全部** 199 個既有測試 |
| LLM 回傳非結構化內容 | Task 拆解出垃圾 | CEO 輸出走 JSON schema 驗證，不合格就重試，禁止自然語言直接當 Task（§28） |
| SSE 長連線佔滿 PHP-FPM worker | API 全站卡住 | 限制單一使用者連線數 + 逾時自動斷線；壓測後再決定是否改用輪詢退路 |
| 規格偏離被誤讀成沒做完 | 驗收爭議 | C1～C5 明列在本文件 §2，每次 PHASE STATUS 回報時複述 |

---

## 14. Definition of Done（規格 §77）

任何 AI Office 功能要同時滿足才算完成：

- [ ] 程式碼實作
- [ ] Migration 實作
- [ ] API 實作
- [ ] 驗證（FormRequest）實作
- [ ] 授權（Policy / PermissionGate）實作
- [ ] 測試實作且**實際跑過**
- [ ] 錯誤處理實作
- [ ] Logging 實作（且不寫入 secret）
- [ ] 文件更新
- [ ] Docker 環境下可運作
