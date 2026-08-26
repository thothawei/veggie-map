# Security — 邊界在哪、為什麼畫在那裡

> 對應 VeggieMap 規格第四十二節與 AI Office 規格 §18～§24、§42～§43、§52～§55。
>
> 這份文件寫「有哪些防線、擋住什麼、以及**哪裡還沒擋住**」。
> 沒有做到的事寫成沒有做到——安全文件最沒有價值的形態是把打算做的事寫成完成式。

## 兩個信任邊界

這個 repo 有兩個性質不同的子系統，威脅模型也不同：

| | VeggieMap（餐廳地圖） | AI Office（多 Agent 平台） |
|---|---|---|
| 不受信任的輸入 | 匿名使用者的查詢與回報、外部 API 回應 | **LLM 產生的工具呼叫** |
| 最壞情況 | 資料汙染、DoS、越權改別人的資料 | 在 host 上執行任意指令、讀走金鑰、動到別的專案 |
| 主要防線 | 驗證／Policy／rate limit | 權限預設拒絕＋沙箱＋人工核准 |

第二欄是這個專案真正特別的地方：**模型的輸出一律當成攻擊者輸入**，不因為 prompt 裡
寫了「請不要 rm -rf」就當它不會。

## 認證與授權

- **認證**：Laravel Sanctum Bearer Token（API-only，未用 SPA cookie 模式）。
  密碼 hashing 用 Laravel 原生，不自行實作。
- **VeggieMap 角色**：`user` ／ `admin`。Admin 動作走 Policy＋`role` 檢查。
- **AI Office 角色**（§52／§53）：`admin` ／ `manager` ／ `developer` ／ `viewer`。
  `EnsureAiOfficeRole` 中介層掛在整組 `/api/v1/ai-office/*` 上。
  **`user` 不在名單裡**——那是只用餐廳地圖的一般消費者，不該因為註冊過就看得到 Agent 面板。
- **Policy**：餐廳側 `RestaurantPolicy`／`ReviewPolicy`／`RestaurantReportPolicy`／
  `RestaurantVerificationPolicy`／`MenuItemPolicy`；AI Office 側 `ProjectPolicy`／
  `TaskPolicy`／`AgentPolicy`／`ApprovalPolicy`。收藏刻意沒有 Policy（只判斷已登入）。

**已知限制：Sanctum token 不會過期**。MVP 可接受，正式營運需要 expiry／refresh，
記在 [todo.md](todo.md)。

## Agent 的三層圍籬

### 1. 權限：預設拒絕

`PermissionGate` 對權限表沒寫的能力一律回 `deny`。判定順序是
deny → approval → allow 再看風險門檻，細節見 [tools.md](tools.md#風險等級與核准門檻)。

### 2. 邊界檢查

| 守衛 | 擋住什麼 |
|---|---|
| `WorkspaceGuard` | 路徑 `realpath()` 後必須仍在 `{workspace_root}/{project_id}/` 內。用 realpath 而非字串前綴，因為 `../` 與 symlink 都能騙過前綴比對 |
| `CommandAllowlist` | allowlist ＋ denylist ＋ metacharacter 三層（分號、管線、反引號、`$(` 全擋，否則 `ls; rm -rf /` 能繞過白名單） |
| `SqlReadGuard` | 只讀語句、關鍵字黑名單、先剝註解再比對、只在 local／testing 環境可用 |
| DockerTool 命名規則 | 只能動 `ai-office-project-{id}*`，且拒絕 `--privileged`／`docker.sock`／`--network=host` |

### 3. 沙箱

通過前兩層的指令**不在 host 上跑**，而是丟進獨立容器
（`SandboxManager` → `DockerSandboxEngine`）：非 root 使用者、`--memory`／`--cpus` 上限、
`--pids-limit`（fork bomb 的第二道防線）、唯讀 rootfs＋tmpfs、預設 `--network none`、
逾時強制中止。只有該專案的 workspace 掛進去。

**沙箱不可用時 TerminalTool 直接拒絕執行，不會退回 host 跑**（`SandboxPolicy::refuseHostExecution()`）。
這是 Phase 5 定下、Phase 11 沒有放寬的規則：寧可功能缺席，不可假裝安全。

**誠實的限制**：啟用沙箱要掛 `/var/run/docker.sock` 進 app container
（`docker-compose.sandbox.yml`），那實質上等同 host root——可以掛 `/` 進新容器。
這是用一個較大的信任邊界，換掉「LLM 產生的指令直接在 app container 裡跑」這個更糟的狀態。
所以那個 compose 檔**不是預設啟用**，而且只適合單人開發機／專用 CI，
不要用在多租戶或不受信任的環境。更嚴格的做法（rootless docker、DinD side-car、gVisor／Kata）
留到真的要對外服務再說。

## 人工核准（Human-in-the-loop）

風險 ≥ `approvals.threshold`（預設 `high`）的操作會建立一筆 `ai_office_approvals`
並**暫停任務**，等人在 `/ai-office/approvals` 按批准或拒絕。核准逾期
（`ttl_hours`，預設 24 小時）自動 expire——過期的核准不會被當成同意。

門檻設成 `off` 也只放寬到「critical 仍必須核准」。`deploy_production` 是 critical，
沒有核准就是不會執行。

## API 面的一般防護

- **Rate limiting**：`/api/v1/*` 每分鐘 60 次，Redis-based，依登入使用者 id 或 IP 分桶，
  超過回 429。搜尋建議端點另有一組較寬的桶（預設 180／分鐘）——自動完成天生每打幾個字
  就是一次請求，跟一般端點共用 60 的話正常打字幾輪就會撞 429、建議整個消失。
- **輸入驗證**：所有寫入端點走 FormRequest；`per_page` 上限 100，擋掉 `per_page=100000`。
- **SQL injection**：一律 Eloquent／查詢建構器綁定參數；空間查詢的座標先經數值驗證。
- **Mass assignment**：所有 Model 明確宣告 `$fillable`。
- **錯誤格式**：統一經 `ApiExceptionRenderer`，production 不外洩例外堆疊。
- **SSE 票券**：`EventSource` 不能帶 Authorization 標頭，所以前端先用 Bearer token 換一張
  一次性、綁使用者＋專案、TTL 60 秒的票再開串流。**不把 Sanctum token 放進網址**
  ——網址會進 access log 與瀏覽器歷史。同時限制單一使用者最多 3 條連線、單條最長 60 秒。

## 機密管理

- API key 只從 `.env` 讀，**不進資料庫、不寫進 log**（§54）。
- `ExternalApiLog` 記 provider／endpoint／狀態／耗時，**不記 key、token、密碼**。
- `.env` 不進版控。`.env.example` 只有空值。
- Horizon／Telescope 在 production 由 `DASHBOARD_ALLOWED_EMAILS`（逗號分隔的環境變數）
  控管，**預設是空的＝沒有人**。email 不寫進程式碼，因為這個 repo 是公開的。

## 已知未解

- **`CVE-2026-48019`（Laravel `email` 規則的 CRLF injection）— 已緩解、未根治**。
  所有吃 email 的 FormRequest 掛 `App\Rules\SafeEmail` 擋控制字元。
  payload 是實測出來的：`user@example.com\r\n…` 那種形狀預設規則本來就會擋（拿它當測試
  等於假保護），真正會通過的是帶引號的 local part `"user\r\n"@example.com`。
  `composer audit` 仍會報三則，真正的修補要升 Laravel 12.61.1+，屬獨立的 major upgrade。
- **Sanctum token 不過期**（見上）。
- **沒有檔案上傳功能**，所以沒有上傳驗證——不是「做了很嚴格的驗證」，是還沒有這個入口。

## 相關文件

- 工具的完整風險分級：[tools.md](tools.md)
- Agent 權限矩陣：[agents.md](agents.md)
- 部署面的安全設定：[deployment.md](deployment.md)
