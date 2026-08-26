# Agents — AI Office 的七個 Agent

> 對應 AI Office 規格 §5～§7、§25～§29、§41、§67。
>
> **真理來源是程式碼**：Agent 名冊與權限在
> [`database/seeders/AiOfficeAgentSeeder.php`](../database/seeders/AiOfficeAgentSeeder.php)，
> 執行上限與記憶設定在 [`config/ai_office.php`](../config/ai_office.php)。
> 這份文件解釋「為什麼長這樣」，數值以那兩個檔案為準。

## Agent 是什麼

不是一段 prompt，是一筆資料列加上執行期的一組約束：

```
Agent = 身分（name/role）
      + system_prompt
      + model_provider / model_name
      + tools[]        （ai_office_agent_tools，掛哪幾個工具類別）
      + permissions{}  （ai_office_agent_permissions，每個能力 allow/deny/approval）
      + memories[]     （ai_office_agent_memories，跨任務的記憶）
      + status
```

`model_name` 全部是 `null`：表示「用 provider 的預設模型」。想讓某個 Agent 用更便宜的
模型，改這一欄就好，不用動程式碼。

## 名冊

| Agent | role | 工具 | 特別權限 | 定位 |
|---|---|---|---|---|
| AI 主管 Michael | `ceo` | 無 | — | 需求分析、拆任務、派工、驗收。**故意沒有工具**：CEO 的產出是計畫不是程式碼 |
| 前端小王 | `frontend` | file, git | 基準權限 | Vue／TypeScript／版面實作 |
| 後端阿明 | `backend` | file, git, database | `database_read: allow` | Laravel／SQL／API |
| 自動化小林 | `automation` | file, terminal | `execute_command: allow` | 腳本與工作流自動化 |
| 測試小美 | `qa` | file, terminal, git | `execute_command: allow` | 跑測試。發現 bug 時開 Bug Task 派回去，不自己改別人的程式碼（§33） |
| 設計小花 | `design` | file | 只有檔案讀寫 | 版面與視覺規格。產出是可被實作的規格文件 |
| 維運小陳 | `devops` | file, git, docker | `git_push: allow`、`deploy_*: approval` | 容器、CI、部署 |

基準權限（`$coderPermissions`）：檔案四個能力與 `git_status`／`diff`／`log`／`branch`／
`checkout`／`add`／`commit` 是 `allow`，**`git_push`／`database_read`／`deploy_*` 是 `deny`**。
只有 devops 的 `git_push` 被明確放開。

三個設計決定值得說明：

- **預設拒絕**。`PermissionGate` 對權限表沒寫的能力一律回 `deny`。新增一個工具時，
  它不會默默地對每個 Agent 生效——必須有人明確把它加進某個 Agent 的權限表。
- **`deploy_staging`／`deploy_production` 目前沒有對應的 Tool 實作**，權限仍然先寫好，
  而且在 `config/ai_office.php` 的 `approvals.ability_risk` 裡分別排成 high／critical。
  能力表先於實作存在，實作出來的那天不會出現「新能力預設是 low 風險」的空窗。
- **Seeder 用 `updateOrCreate` + 每次重建權限列**。從名冊拿掉一個權限，重跑 seeder
  那筆 allow 就會消失，不會留下一個沒人記得的授權。

## Agent 狀態

`idle` ／ `working` ／ `waiting_review` ／ `error` ／ `offline`
（`App\AiOffice\Models\Agent::STATUSES`）。

前端 `AgentStatusBadge` 對應成 🟢 工作中／🟠 等待審核／🔴 錯誤／⚪ 待命／⚫ 離線。
狀態一律來自資料庫，SSE 推播變動——UI 不自己造狀態（§7、§46）。

## 派工：AgentSelector

`App\AiOffice\Orchestration\AgentSelector::select(string $role)` 在該 role 裡挑人：
排除 `offline`，然後依「先 idle → 目前任務數少的 → id 小的」排序取第一個。

兩個刻意的決定：

- **不解析任務標題裡的關鍵字去猜角色**（看到「Laravel」就派給 backend）。那會變成一張
  寫死又會漂的關鍵字表，而且跟 CEO 規劃 JSON 裡已經帶的 role 重複。角色由 schema 決定。
- **並行上限不在這裡擋**。就算所有人都忙，仍然會留下指派——否則任務連「該找誰」都忘了。
  要不要真的 dispatch `ExecuteTaskJob`，由 `AgentOrchestrator` 看該 Agent 的 running 數
  與 `max_concurrency` 決定。

CEO 拆出來的任務只能指定 `config('ai_office.planner.assignable_roles')` 裡的六個角色
（frontend／backend／automation／qa／design／devops）——CEO 不能把任務指派給自己。

## 執行迴圈：AgentRuntime

```
載入 Agent → 組 system prompt（含 recall 到的記憶）→ 呼叫 LLM
  → 有 tool call？→ PermissionGate 判定 → allow 執行／approval 建核准並暫停／deny 拒絕
  → 把工具結果餵回去 → 下一輪
  → 沒有 tool call → 收尾，寫 task_run、token 用量、記憶
```

四個硬上限（`config/ai_office.php` 的 `limits`，規格 §26）：

| 上限 | 預設 | 擋住什麼 |
|---|---|---|
| `max_agent_steps` | 25 | 無限迴圈 |
| `max_tool_calls` | 50 | 工具呼叫爆炸 |
| `max_retries` | 3 | 失敗任務無限重試 |
| `max_token_budget` | 200000 | 單一任務燒光預算 |

撞到上限是**中止並把任務標成 failed**，不是印個警告繼續跑。每次執行寫一筆
`ai_office_task_runs`（含 token 與耗時），失敗另外寫 `ai_office_agent_errors`。

## Agent Memory

每跑完一個任務寫一則記憶：成功寫 `task_result`（重要度 5），失敗寫 `error_pattern`
（重要度 7——失敗比成功值得記）。下次執行時取重要度最高的前
`memory.recall_limit`（預設 5）則放進 prompt。

有上限的理由很實際：記憶是要塞進 context 的，無上限地塞等於每次請求都在為舊資訊付錢。
單則超過 `max_content_length` 是截斷而非拒絕寫入——寧可記一半，不要整則掉。

第一版存在 MySQL，沒有向量檢索（規格 §41 明確要求第一版不要上 Vector DB）。

## Agent 之間怎麼講話

`ai_office_messages` 存跨 Agent 訊息（CEO → Backend、QA → Backend…），
前端 `MessageFeed` 顯示。這張表從 Phase 2 就建好，但**一直到 2026-08-26 才真的有東西寫進去**
——在那之前 `handleFailure()` 註解寫著「通知 CEO」，卻沒有任何東西送到 CEO 手上。
表存在不等於功能存在，這是這個子系統踩過最典型的一次。

## 相關文件

- 工具與風險分級：[tools.md](tools.md)
- 沙箱、核准、權限邊界：[security.md](security.md)
- 整體架構與 Phase 規劃：[implementation-plan.md](implementation-plan.md)
