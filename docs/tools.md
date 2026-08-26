# Tools — Agent 能做的事，以及做不到的事

> 對應 AI Office 規格 §15～§22。
>
> **真理來源是 [`config/ai_office.php`](../config/ai_office.php) 的 `tools` 區塊**：
> 風險等級、白名單、禁止字串全部在設定檔裡。工具類別
> （[`app/AiOffice/Tools/`](../app/AiOffice/Tools)）只負責執行與讀設定，
> 不寫死 `if ($cmd === 'rm -rf /')` 這種判斷。

## 共同契約

每個工具實作 `ToolInterface`：`name` ／ `description` ／ `input_schema` ／
`permission`（能力名稱）／ `execute()`。每次呼叫寫一筆 `ai_office_tool_executions`。
輸出超過 `tools.max_output_bytes`（預設 32KB）會截斷——工具輸出是要進 LLM context 的，
一個 `cat` 大檔就能把預算燒光。

執行前一律經過三道關卡：

```
PermissionGate（Agent 有沒有這個能力）
  → RiskLevel（這個能力的風險是否 ≥ 核准門檻）
  → 工具自己的邊界（WorkspaceGuard／CommandAllowlist／SqlReadGuard／容器命名規則）
```

## 五個工具與風險分級

### FileTool

`read_file`／`list_files`／`search_files`（low）、`write_file`（medium）。

**邊界：`WorkspaceGuard`**。Agent 給的任何路徑經 `realpath()` 之後必須仍落在
`{workspace_root}/{project_id}/` 內，否則拒絕。用 realpath 而非字串比對，是因為
`../` 與 symlink 都能讓字串前綴檢查通過卻指向別的地方。跨專案存取一律拒絕（§42）。

讀寫各有大小上限（預設讀 512KB／寫 1MB）。寫入會同步更新 `ai_office_project_files`，
所以「這個專案產生了哪些檔案」是查得到的，不必去掃磁碟。

### GitTool

`git_status`／`diff`／`log`／`branch`（low）、`checkout`／`add`／`commit`（medium）、
`git_push`（**high** → 預設要人工核准）。

- `protected_branches`：`main`／`master`。Agent 不能直接推主幹（§62）。
- `ssh_command` 預設是 `false`（字面上的 `false` 指令），**刻意讓 SSH 不可用**，
  避免 Agent 用到 host 的 `~/.ssh`。要讓 Agent 真的能 push，得另外配一把沙箱內的 deploy key。

### TerminalTool

`execute_command`（medium），但真正決定能不能跑的是三層過濾（`CommandAllowlist`）：

1. **allowlist**：指令必須完全等於清單中某一項，或以「該項＋一個空白」開頭。
   目前只有 `php artisan test`／`migrate`／`pint`、`phpunit`、`vendor/bin/phpstan`、
   `npm test`／`npm run build`、以及 `ls`／`cat`／`head`／`tail`／`wc`／`echo`。
2. **denylist**：即使被加進 allowlist 也硬擋——`rm -rf /`、`shutdown`、`reboot`、
   `sudo`、`mkfs`、`.ssh`、`id_rsa`、`docker.sock`、fork bomb 的 `:(){`。
3. **metacharacter**：`;`｜`|`｜`&`｜`` ` ``｜`$(`｜換行｜`>`｜`<` 一律拒絕，
   否則 `ls; rm -rf /` 這種串接能繞過前兩層。

通過之後指令**不在 host 上跑**，而是丟進沙箱容器（見 [security.md](security.md#沙箱)）。
沙箱不可用時 TerminalTool **直接拒絕執行**，不會退回 host——寧可功能缺席，不要假裝安全。

### DockerTool

`docker_build`／`docker_run`／`docker_logs`／`docker_stop`（medium）。

**預設整組關閉**（`sandbox.docker_tool_enabled = false`）：接上等於讓 Agent 能建立與啟動
容器，那是另一個層級的權限。開啟後仍受限：

- 映像／容器名稱必須符合 `^ai-office-project-{id}(-…)?$`——只能動自己專案的東西。
- 參數含 `docker.sock`、`--privileged`、`--network=host`、`--pid=host`、`/:/`
  一律拒絕（這些等於把 host 交出去）。

### DatabaseTool

`database_read`（low）。第一版只讀（§20）：

- 只接受 `select`／`explain`／`describe`／`desc` 開頭的語句；
- 關鍵字黑名單擋 `drop`／`truncate`／`delete`／`update`／`alter`／`insert`／`outfile`…；
- 比對前先剝掉 SQL 註解，避免 `SELECT/*x*/…; DROP` 這種夾帶；
- 只在 `local`／`testing` 環境可用（`allowed_environments`），production 完全不通；
- 最多回 100 列。

## 風險等級與核准門檻

四級：`low` ／ `medium` ／ `high` ／ `critical`。

判定順序（`PermissionGate::decide()`）：

```
權限 deny      → 立刻拒絕（風險再低也不行）
權限 approval  → 建立核准，任務暫停
權限 allow     → 再看風險：≥ approvals.threshold（預設 high）仍要核准
```

`threshold` 設成 `off` 也**只**放寬到「critical 仍必須核准」——規格 §24 的底線改設定改不掉。
無效值回退成 `high`（不是回退成放行）。

目前落在 high／critical 的能力：`git_push`（high）、`deploy_staging`（high）、
`deploy_production`（critical）。

## 加一個新工具要做什麼

1. 實作 `ToolInterface`，把邊界檢查放在專屬的 Guard 類別裡，不要塞進 `execute()`。
2. 在 `config/ai_office.php` 的 `tools.<name>.actions` 宣告每個能力的風險等級。
3. 在 `AiOfficeAgentSeeder` 把工具掛給該掛的 Agent，並明確寫權限。
   **不寫就是 deny**——不會因為工具存在就自動生效。
4. 補測試：至少要有一條「越界被拒絕」的測試，而且要能證明把守衛拿掉它會紅。

## 相關文件

- Agent 名冊與權限矩陣：[agents.md](agents.md)
- 沙箱、核准流程、其餘安全邊界：[security.md](security.md)
