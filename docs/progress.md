# Progress Log

## 2026-08-25 — AI Office Phase 6：人工核准與風險門檻

**完成：**

- `PermissionGate::decide()`：Agent 權限 deny → 拒絕；權限 approval **或** 風險達門檻 →
  要核准；其餘 allow。權限表沒寫仍是預設拒絕。
- `RiskLevel`：門檻讀 `config('ai_office.approvals.threshold')`（預設 `high`，含以上要核准）。
  `critical` 一定要核准，即使 threshold=`off`。無效 threshold 回退 `high`。沒有 Tool 實例
  時風險讀 `approvals.ability_risk`（`deploy_production` → critical）；再沒有就當 critical。
- seeder 裡 devops 的 `git_push=allow` 代表「可以請求」，不是「跳過人工」。預設 high 門檻
  下 git_push 仍要核准。
- Runtime 遇到要核准時寫 `ai_office_approvals`，工具狀態 `pending_approval`，任務／Agent
  轉 `waiting_review`，**不執行工具**。
- `ApprovalService` + `ProcessApprovalJob`：HTTP 裡只標記 approved／rejected。核准後 Job
  才跑工具，任務改 `assigned`、Agent 改 `idle`，再 `tryDispatch`。拒絕則任務 `rejected`、
  工具 `denied`；`refreshProjectStatus` 把 `rejected` 當永久失敗。
- 過期：`expires_at`（TTL `approvals.ttl_hours`，預設 24h）。列表／核准前 `expireOverdue()`。
  過期再核准 → 422。
- API：`GET/POST /ai-office/approvals`。viewer／developer 可看；只有 admin／manager 能核准／拒絕。

**驗收：** 後端 327 測試 959 assertion 全綠；Pint／PHPStan 乾淨。前端這輪沒動（Approval Panel 是 Phase 8）。

**反向：**

- threshold 改成 `critical` 後，`git_push` + allow 會真的執行、不建 Approval。
- threshold=`off` 時 high 可執行，**critical 仍暫停**。拿掉 `RiskLevel` 裡
  `if ($level === 'critical') return true` 這條，`test_off_still_forces_critical` 會紅。
- `ability_risk.deploy_production` 改 config 會跟著變。
- 拒絕後 `RecordingTool::callCount() === 0`（不是只看 status）。

**下一步：** Phase 7 — Activity + SSE。

---

## 2026-08-25 — AI Office Phase 5：五個 Tool + 路徑／指令／SQL 硬邊界

**完成：**

- `FileTool`／`GitTool`／`TerminalTool`／`DockerTool`／`DatabaseTool` 登記進
  `ToolRegistry`，動作名稱與 `agent_permissions.ability` 同一套。風險等級讀
  `config/ai_office.php` 的 `tools.*.actions.*.risk`。
- `WorkspaceGuard`：`realpath()` 之後必須落在該專案 workspace。擋 `..`、絕對系統路徑、
  空位元組、symlink 逃逸、跨 Project、非法 `workspace_path`。
- `CommandAllowlist`：allowlist 前綴 + denylist regex 硬擋 + 禁止 `;` `|` `$()` 等
  中介字元。denylist 即使被加進 allowlist 也贏。
- `SqlReadGuard`：前綴白名單 + 關鍵字黑名單；多句 SQL 拒絕。production 不在
  `allowed_environments` 裡。
- `SandboxPolicy`：`SANDBOX_ENABLED=true`（預設）時 Terminal／Docker 拒絕在 host 執行。
  Docker 預設引擎是 `UnavailableDockerEngine`，就算有人把沙箱關掉也不碰 host docker.sock。
- `git_push` 受保護分支（預設 main／master）在跑 git 之前就拒絕。SSH 預設
  `GIT_SSH_COMMAND=false`，避免用到 host 的 `~/.ssh`。

**驗收：** 後端 314 測試 914 assertion 全綠；Pint／PHPStan 乾淨。前端這輪沒動。

**反向：**

- denylist 拿掉之後，allowlist 裡的 `rm -rf /` 會過——證明擋下來的是 denylist。
- config 拿掉 `select` 前綴，`SELECT 1` 變非法。
- `protected_branches` 改成 `develop`，push develop 會被拒。
- Docker `name_pattern` 改成 `custom-{id}` 後，原本合法的 `ai-office-project-{id}` 被拒。

**下一步：** Phase 6 — Approval / RiskLevel / human-in-the-loop。

---

## 2026-08-25 — AI Office Phase 4：規劃／派工／佇列／重試

**完成：**

- `CeoPlanner` + `PlanSchema`：從 LLM 抽出 JSON（含 markdown fence），驗證 title／
  agent／dependencies，擋重複 title、未知 title、環。角色白名單讀
  `config/ai_office.php` 的 `planner.assignable_roles`，不寫死 `backend`。
  自然語言清單（沒有 `{...}`）回 null，不會被拆成任務。
- `AgentSelector`：只依 role + idle 優先 + 最低 workload。標題再像後端工作，沒有
  該角色的 Agent 就回 null，不猜。
- `AgentOrchestrator`：`planProject` → 建 DAG → 指派 → `dispatchReadyTasks`。
  規劃失敗只把 `planning` 標 `failed`；派工不包在同一個 catch，避免執行失敗被當成
  規劃失敗。並行上限在 **dispatch** 時看 `running` vs `max_concurrency`，有人可派就
  先留下 `assigned_agent_id`。
- Jobs：`PlanProjectJob`（unique `plan-{id}`）、`ExecuteTaskJob`、`RetryFailedTaskJob`，
  獨立佇列 `ai-office`，timeout／tries 讀 config。領域重試不跟 Laravel job retry 疊加。
  Horizon 新增 `supervisor-ai-office`。
- `POST /projects` 只 dispatch 規劃 Job；人手建任務／PATCH 指派後走 `tryDispatch`。
  規劃階段的 token 與 activity 可以沒有 task。

**過程中修掉的 bug：** `ExecuteTaskJob` 若加 `ShouldBeUnique`，sync 佇列下失敗重試會在
同一輪 `afterTaskRun` 再 dispatch 自己，unique lock 還沒放掉，第二次執行被默默丟掉，
任務卡在 `assigned`。改成狀態檢查 + 原子 `UPDATE ... WHERE status IN (pending, assigned)`
搶占，重複派工第二個 worker 直接 return。

**驗收：** 後端 273 測試 816 assertion 全綠；Pint／PHPStan 乾淨。前端這輪沒動。

**反向：**

- 拿掉 `handleFailure` 裡的 `RetryFailedTaskJob::dispatch`，重試測試 `retry_count`
  停在 1（預期 2）——確認不是裝飾品。
- `PlanSchemaTest`：config 拿掉 `backend` 後含 backend 的規劃會丟
  `PlanValidationException`。
- `AgentSelectorTest`：標題再像後端，沒有 backend Agent 就回 null。

**下一步：** Phase 5 — 五個 Tool + PermissionGate + WorkspaceGuard + CommandAllowlist。

---

## 2026-08-25 — P1：素食可信度的寫入路徑

**完成：**

- `POST /api/v1/admin/restaurants/{id}/verifications`（＋`GET /admin/verification-types`
  給前端下拉）。合法類型來自 `config/vegetarian.php` 的 `admin_verifiable_types`
  （`admin_verified`／`menu_verified`），分數來自 `verification_weights`，呼叫端不能指定分數。
  `external_source`／`restaurant_claim`／`photo_verified` 刻意不開放手寫。
- 回報核准依同一份 config 的 `report_verifications` 寫 `user_report`：`closed`（店都倒了）
  與 `other`（內容不固定）對到 null 不寫，其餘五種寫一筆。同店多次回報因為 Job 依類型
  取最高分只算一次 +10，不會被灌分。
- 分數重算改掛 `RestaurantVerificationObserver`（saved／deleted），OSM 同步不再自己
  dispatch；餐廳詳情頁的 admin 區塊多了「標記驗證」下拉，送出後重載看得到分數變化。
- 順手補上 Phase C 留下的缺口：`not_vegetarian` 降級後重算 `external_source` 分數
  （exclusive 10 → friendly 5），以前要等下次 OSM 同步才修正，中間可信度是虛高的。
  沒有 `external_source` 的手動建店不會憑空生出一筆。

**驗收：** 後端 199 測試 586 assertion、前端 104 測試全綠；Pint／PHPStan／vue-tsc／
eslint／build 乾淨。反向：拿掉 `recordVerification()` 後端紅 2 條；拿掉前端
`if (!auth.isAdmin) return` 守衛前端紅 1 條。

**過程中修掉的兩個 bug：**

- `$report->reviewedBy` 打錯（關聯叫 `reviewer`），Eloquent 對不存在的關聯靜默回 null，
  驗證的 `verified_by` 會全部是 null——測試斷言 admin id 才抓到。
- 把 sync 的顯式 dispatch 拿掉後，`syncExternalSource` 收合重複列用的
  `whereIn(...)->delete()` 是 query delete、不觸發 observer，分數會停在被刪掉那筆的
  10 分。改成逐筆 model delete。

---

## 2026-08-25 — P0 Phase C：菜單層葷／素

**完成：**

- `config/diet.php` 的 `menu_item_diets` 當菜單 diet 單一真相：`GET /diets` meta、
  `CreateMenuItemRequest`、`MenuItemResource.diet_label`、`MenuItemFactory`、詳情頁
  分組標籤都讀它，Vue 沒有寫死「全素／葷食」。
- 詳情頁有菜單才依 meta 順序分組；空陣列顯示 `menu_empty_message`（exclusive／
  friendly × osm／其他，文案在 `copy`）。OSM sync 不編造 `menu_items`（既有
  匯入測試加了 count=0）。沒做 cuisine 當假菜色。
- `POST /api/v1/admin/restaurants/{id}/menu-items`，Policy 只有 admin。菜單異動
  走 `MenuItemObserver` 清 detail cache。
- 回報核准連動放 `report_actions`：`not_vegetarian` 對 exclusive 店
  `demote_to_friendly`（同 osm_tag 對到 friendly code，不是寫死 vegan→vegan_friendly）、
  對 friendly 店 `remove_exclusive_codes`；`menu_changed` 清空菜單。`closed` 仍 noop。
  Controller 只呼叫 `ReportConsequenceService`。

**驗收（瀏覽器）：** 種子店「Lakin, Hartmann and Roob 蔬食」菜單分成全素／素食／葷食／
未標示；東京 CoCo壱番屋（OSM 友善、0 筆菜單）顯示「OSM 標示此店有素食選項，菜單尚未
建檔。」、畫面上 0 道菜。Admin 新增走測試庫／元件測試，沒對開發庫的 OSM 店寫入。

**驗收時修掉的 bug：** `clear_menu_items` 原本用 `each()`（offset 分頁）一邊翻頁一邊
刪，1005 筆的菜單實測只刪掉 1000 筆、殘留 5 筆。改成 `lazyById()`（以 id 遞增取批，
刪除不影響下一批），並補上 1005 筆的回歸測試——拔掉修正該測試會紅。

**反向：** 暫時拿掉核准後的 `apply()`，「exclusive 店 not_vegetarian 降為 friendly」
那條從 vegan_friendly 變成還停在 vegan，確認不是裝飾品。

**已知取捨：** `ovo_lacto`（沒有 OSM 標籤、沒有友善對應）的店被核准 `not_vegetarian`
之後 diet 會清空而不是降級——實測 codes 變成 `[]`。這是 `friendlyCounterpart()` 明寫的
行為（不硬湊 vegan→vegan_friendly），不是漏寫。

後端 183 個測試、538 assertion 全綠；前端 101 個；Pint／PHPStan／vue-tsc／build 乾淨。

---

## 2026-08-25 — P0 Phase B：台灣也收友善店

**完成：**

- `.env`／`.env.example` 台灣四市 bbox 從 `@only` 改成 `@yes`（規則名仍是
  `config/diet.php` 的 `sync_modes` key）。
- 排程測試改成「每個 region 的 diet 都在白名單」＋「東京模式含 osm value yes」，
  不再寫死四個 only、一個 yes。
- 前端預設 `venue_scope` 維持 exclusive，首頁不會因為台灣改收友善店就變成全是火鍋。
- 實跑：台南 45→186（141 新友善）、台北 222→414、台中 177→246、高雄更新後 297。
  四市 `whereDoesntHave('dietTypes')` 仍為 0；十方齋仍 exclusive。Overpass `out count`
  對台南 bbox 回 504（查詢 timeout 25s），改以實際 `out body` 同步筆數對過量測（186≈187）。

---

## 2026-08-25 — P0 Phase A：葷素混合店分得清

**完成：**

- `config/diet.php` 當單一真相：diet types（含 osm_tag／osm_values／kind／group_label）、
  sync_modes、venue_scope、copy、confidence、osm amenities／features。Seeder、
  `GET /diets`、Overpass 組 query、映射都讀這份。
- OSM：`only` → exclusive codes，`yes` → friendly codes。東京 `@yes` 收進來的友善店
  不再被標成 `vegan`／`vegetarian`。
- Diet 同步策略：OSM 管得到的 code 改成「這次算出的集合」（錯標會被換掉）；沒有
  osm_tag 的手動關聯留下。特色仍 `syncWithoutDetaching`。
- `GET /restaurants` 與 recommended 加 `venue_scope`（省略＝不過濾；前端預設送 exclusive）。
  FilterDrawer 範圍晶片與 diet 分組都依 `/diets` meta／kind，不寫死清單。
- 卡片／popup／詳情用 API 的 `venue_badge`／`venue_summary`。友善店 `external_source`
  分數走 config（5），exclusive 仍 10；重跑 sync 會更新既有分數。
- 台灣四市 `@only` 沒動，留給 Phase B。

**設計點：** 兩種關聯不能共用 `syncWithoutDetaching`——否則東京友善店上錯掛的
`vegetarian` 永遠拔不掉。OSM-managed 用 replace，手動非 OSM code 才保留。

---

## 2026-08-24 — Phase 0: Architecture & External API Research

**完成：**

- 建立 repo（`~/Documents/veggie-map`，全新 git repository，與 tower-defense 專案無關）。
- 逐一以 WebFetch 讀取原始文件確認（非憑記憶）：`public-apis` Food 分類、OSM Nominatim usage policy、
  Overpass API wiki、OpenStreetMap copyright/ODbL、Open Food Facts 官方說明。
- 結論：`public-apis` 清單無合適的免費「餐廳＋地理位置＋素食標籤」API，改採總 prompt 預期的
  OSM 路線——Overpass API 匯入 + 自建 Restaurant DB + MockRestaurantProvider 保底。細節見
  [external-apis.md](external-apis.md)。
- 產出 [architecture.md](architecture.md)：系統圖、技術選型 Why、Confidence Score／Rating／Cache
  Invalidation／資料同步管線設計、MVP 邊界。
- 產出 [database.md](database.md)：13 張表的欄位、型別、Index 設計與理由，含 Spatial Index 的
  Bounding Box + 精算距離兩段式查詢策略。
- 產出 [api.md](api.md)：端點清單、統一回應格式、查詢參數、Cursor Pagination 決定。

**未完成 / 等待確認：**

- Phase 1（Laravel project initialization）尚未開始，依總 prompt 第 46 節規則，Phase 0 完成後
  需先回報使用者並取得確認才進入下一階段。
- Nominatim 商業使用政策偏保留（見 external-apis.md），若這個專案未來要真的公開營運需要重新評估，
  MVP／Portfolio Demo 範圍內風險視為可接受。

## 2026-08-24 — Phase 1: Laravel Project Initialization

**完成：**

- Laravel 11.56（PHP 8.2）：在乾淨的 `composer:2` Docker container 裡跑 `composer create-project`，
  完全不動主機既有的全域 composer 設定（`~/.composer/config.json` 有 `audit.abandoned=fail` /
  `policy=true`，一開始以為是使用者自訂的全域安全政策，實測後發現連全新的 container 也一樣擋，
  代表這其實是 Composer 對已知安全公告版本的**標準行為**，不是主機客製設定——先跟使用者確認過
  才在**專案層級**的 `composer.json`（`config.policy.advisories.block = false`）加例外，不是改全域）。
- 依賴解析後來又在 `app` container（真正跑 PHP 8.2 的環境）裡重跑一次 `composer update`——
  第一次是用 `composer:2` image 本身的 PHP 8.4 去解依賴，導致 lock file 選到需要 PHP 8.4 的套件版本，
  跟實際跑的 8.2-fpm 對不上，`php artisan migrate` 直接 Fatal error。這是「用哪個 PHP 版本跑
  composer」跟「容器實際跑哪個 PHP」要對齊的坑，記錄下來避免下次再踩。
- Docker：`docker-compose.yml`（app / nginx / mysql / redis）＋ `docker/php/Dockerfile`
  （PHP 8.2-fpm + pdo_mysql/redis/gd/bcmath 等擴充）＋ `docker/nginx/default.conf`。
  MySQL host port 改 3307、nginx host port 改 8080——3306/80 在這台機器上已經被其他專案的
  Docker 佔用，容器內部 port 不動，只調 host 對外映射。
- `.env.example` / `.env`：`DB_*`／`REDIS_*` 指向 docker-compose 服務名稱（`mysql`/`redis`），
  補上 `EXTERNAL_API_*`（Overpass／Nominatim／mock provider 開關，對應 `docs/external-apis.md`）。
- 實測驗證（不是只跑完指令就交差）：
  - `docker compose up -d --build` 四個 container 全部 Running。
  - `php artisan migrate --force`：users/cache/jobs 三張預設表建立成功，證明 MySQL 連線正常。
  - Tinker 裡 `Redis::ping()` 和 `DB::connection()->getPdo()` 都回 OK。
  - `curl http://localhost:8080/` 回 HTTP 200，證明 nginx → php-fpm → Laravel 整條路徑通。

**安全性備註（記錄，非本階段要修）：**

`composer audit` 顯示 laravel/framework 目前有 3 筆公告，其中 `CVE-2026-48019`
（預設 `email` 驗證規則的 CRLF injection）明確涵蓋 Laravel 11.x，官方修法是升級到 12.60+/13.10+。
VeggieMap 的 User/Report 表單都會用到 email 驗證（Phase 4/5），屆時要嘛升級 Laravel 版本、
要嘛在 FormRequest layer 額外處理，不要延到 Phase 13 部署前才想到。

**未完成 / 等待確認：**

- README、`.gitattributes` 等仍是 Laravel 預設內容，尚未寫成 VeggieMap 自己的說明——這是 Phase 11
  的工作，Phase 1 只要求「能跑起來」，先不提前做。
- 尚未安裝 Sail／Horizon／Sanctum 等後續 Phase 才需要的套件，避免這個階段就把依賴裝一堆用不到的。

## 2026-08-24 — Phase 2: Database

**完成：**

- 13 張表的 migration 全部依 `docs/database.md` 實作完成，欄位/型別/index 與文件一致
  （`restaurants.location` 用 `POINT SRID 4326` + Spatial Index，其餘複合 index／unique 均對齊文件）。
- 11 個 Eloquent Model（`Restaurant`／`DietType`／`Feature`／`MenuItem`／`RestaurantVerification`／
  `RestaurantConfidenceScore`／`RestaurantReport`／`Favorite`／`Review`／`ExternalApiLog`，加上
  更新後的 `User`）與對應關聯（`belongsToMany`／`hasMany`／`hasOne`／`belongsTo`）全部建立。
  `restaurants.location` 沒有原生 Eloquent cast，寫入一律走 `DB::raw('ST_SRID(POINT(lng, lat), 4326)')`。
- 10 個 Factory（含台灣城市/行政區的合理經緯度範圍，取代 Faker 預設的全球隨機座標）；
  `diet_types`／`features` 是固定清單，改用 `DietTypeSeeder`／`FeatureSeeder` upsert 而非 factory 隨機產生。
  `RestaurantSeeder` 產 20 家餐廳並掛上 diet types／features／3~8 筆 menu items。
- 實測驗證：`php artisan migrate:fresh --seed --force` 全部 DONE；Tinker 內確認
  `Restaurant::with(['dietTypes','features','menuItems'])` 關聯正確載入、`ST_Distance_Sphere` 半徑查詢
  可正常排序（20 家餐廳、106 筆 menu items）。

**未完成 / 等待確認：**

- `restaurant_confidence_scores` 尚無 seed 資料（設計上由 Phase 6/7 的 `CalculateRestaurantScoreJob`
  批次寫入，不在 Phase 2 範圍內）。
- `composer audit` 的 `CVE-2026-48019`（email 驗證 CRLF injection）仍待處理，同 Phase 1 備註，
  最晚 Phase 4/5 的 User/Report 表單要一併解決。

## 2026-08-24 — Phase 3: API / Repository Layer（餐廳列表＋詳情）

**完成：**

- `routes/api.php` 啟用，`bootstrap/app.php` 加 `api: routes/api.php` + `apiPrefix: 'api/v1'`。
- `App\Exceptions\ApiExceptionRenderer`：統一 `/api/*` 錯誤回應為 `docs/api.md` 的
  `{success:false, error:{code,message}}` 格式（`ValidationException`／`ModelNotFoundException`／
  `NotFoundHttpException`／`AuthenticationException`／`AuthorizationException`／其他 `HttpExceptionInterface`
  各自映射，非 `/api/*` 請求回 `null` 交還預設 handler）。
- `RestaurantRepository::search()`：`docs/database.md` 的半徑搜尋兩段式查詢——
  Bounding Box WKT polygon + `MBRContains` 過濾（`ST_GeomFromText` 先不帶 SRID 算完再
  `ST_SRID(...,4326)` 貼標籤，跟既有 `RestaurantFactory` 寫入 `location` 的手法一致，避開 MySQL 8
  對 SRID 4326 的軸序驗證），再用 `ST_Distance_Sphere` 算精確距離。`distance` 是計算欄位，
  MySQL 不能在同一層 WHERE 引用 SELECT 別名，改用 `fromSub` 包一層讓外層可以對 `distance`
  做 WHERE／ORDER BY／Cursor 分頁比較。支援 `keyword`／`city`／`district`／`diet`／`price_level`／
  `rating_min`／`pet_friendly`／`parking` 篩選與 `distance`／`rating`／`popular`／`newest` 排序。
- `SearchRestaurantRequest`：查詢參數驗證，`sort=distance` 沒帶座標時回 422 而非悄悄退回其他排序。
- `RestaurantResource`／`MenuItemResource`；`RestaurantController@index`／`@show`。
- 實測（真的打 HTTP，不只跑 artisan test）：
  - `GET /api/v1/restaurants` 列表、`?latitude=&longitude=&radius=` 半徑搜尋（距離遞增排序正確）、
    Cursor 分頁真的翻到第二頁且無重複/漏筆、`sort=rating`、`?diet=vegan` 篩選皆正常。
  - `GET /api/v1/restaurants/{id}` 詳情頁正確帶出 `dietTypes`／`features`／`menuItems`。
  - 404（`ModelNotFoundException`）與 422（自訂驗證錯誤）都回文件要求的錯誤格式。
  - `EXPLAIN` 確認 `restaurants_location_spatial` 出現在 `possible_keys`（predicate 寫法可用該索引）；
    但目前只有 20 筆種子資料，optimizer 判斷整表掃描比走索引便宜而選擇 `type=ALL`——這是 MySQL
    對小表的正常決策，不是查詢寫錯，資料量大了之後才有意義重新驗證是否真的走索引。

**未完成 / 等待確認：**

- `open_now` 篩選參數目前**沒有實作**——schema 裡沒有任何營業時間欄位/表，`docs/database.md`
  也未設計。要嘛之後補 `opening_hours` 相關表，要嘛從 `docs/api.md` 拿掉這個參數，先回報不擅自二選一。
- `/diets`、`/features`、收藏／評論／回報／auth（Sanctum 尚未安裝）等端點仍未實作，留給後續 Phase。
- `RestaurantController`／`RestaurantRepository` 目前沒有自動化測試（`tests/Feature`），
  這次驗證全靠手動 curl／tinker／EXPLAIN，之後要補 Feature test 固化這些行為。

## 2026-08-24 — Phase 3 續：`/diets`、`/features`

**完成：**

- `open_now` 決議：先擱置、不動 schema，`docs/api.md` 參數列表照舊保留，等未來真的要做
  營業時間才回來補 `opening_hours` 表與篩選邏輯。
- `GET /api/v1/diets`、`GET /api/v1/features`：固定清單（各 7／8 筆），資料量小不分頁，
  共用一個 `LookupController`（`dietTypes()`／`features()`）而非各開一個 controller。
  `DietTypeResource`／`FeatureResource` 只回 `code`／`label`。
- 實測：兩支端點都打過 HTTP，回傳筆數與內容跟 `DietTypeSeeder`／`FeatureSeeder` 的固定清單一致。

**未完成 / 等待確認：**

- 收藏／評論／回報／auth（Sanctum 尚未安裝）、Feature test 仍未動工，留給下一階段。

## 2026-08-24 — Phase 4: Auth（Sanctum）＋ 收藏

**完成：**

- `composer require laravel/sanctum`：在 `app` container（PHP 8.2，跟 Phase 1 記錄的坑一致，
  沒有用 composer:2 image 本身的 PHP 8.4 去解依賴）安裝，`personal_access_tokens` migration
  已跑。`User` model 加 `HasApiTokens` trait。
- `AuthController`：`POST /auth/register`（`RegisterRequest` 驗證，`password confirmed`）／
  `POST /auth/login`（`Hash::check` 失敗回 `ValidationException`，不洩漏帳號是否存在）／
  `POST /auth/logout`（撤銷當前 token，`auth:sanctum` 保護）。純 Bearer token 認證，
  沒有用 Sanctum 的 SPA cookie/stateful 模式（這個專案是 API-only，不需要）。
- `MeController@show`（`GET /me`）、`FavoriteController`（`GET /me/favorites` cursor 分頁列表、
  `POST`／`DELETE /restaurants/{id}/favorite`）。收藏加入用 `firstOrCreate` 冪等；
  取消收藏對本來就沒收藏的餐廳也回成功（冪等，見程式碼註解），不特別開 `FavoritePolicy`——
  這個動作除了「已登入」以外沒有其他授權判斷維度，`docs/api.md` 講的 Policy 針對的是
  Review／Report／Restaurant 那種有 ownership／審核角色的資源。
- 實測全流程（真打 HTTP）：register → 重複 email 422 → login 錯密碼 422 → login 成功 →
  無 token 打 `/me` → favorite → 重複 favorite 冪等 → `/me/favorites` 有資料 → unfavorite →
  `/me/favorites` 清空 → logout → 用同一個 token 再打 `/me` 確認 401（token 真的被撤銷）。

**過程中抓到並修掉 2 個真的 bug（不是憑空猜的）：**

1. `/me` 沒帶 token 原本回 **500**（`Route [login] not defined.`），不是文件要求的 401。
   原因：Laravel 預設 `Authenticate` middleware 在請求沒有明確 `Accept: application/json` header
   時，`redirectTo()` 會嘗試 `route('login')`——這個純 API 專案根本沒有 `login` 具名路由，
   丟出的 `RouteNotFoundException` 蓋掉了原本該回的 `AuthenticationException`。
   修法：`bootstrap/app.php` 用 `$middleware->redirectGuestsTo(fn () => null)` 強制永不重導。
2. 註冊回應裡 `user.role` 原本是 `null`，但 DB 實際存的是 migration default 的 `user`
   （`Eloquent::create()` 後記憶體 model 不會自動回填 DB-side default）。修法：
   `AuthController::register()` 顯式帶 `'role' => 'user'`，不依賴 DB default 反映到回應。

**未完成 / 等待確認：**

- 評論／回報端點、`FormRequest`／`Policy`（`ReviewPolicy`／`ReportPolicy`）仍未實作。
- `AuthController`／`FavoriteController` 沒有自動化測試，跟 Phase 3 一樣先靠手動驗證，
  之後要補 Feature test。
- Token 沒有 expiry／refresh 機制，`config/sanctum.php` 目前是預設值（`expiration => null`
  永不過期）——MVP／Portfolio Demo 範圍可接受，正式營運要重新評估。

## 2026-08-24 — Phase 5: 評論／回報

**完成：**

- `ReviewService::submit()`：`reviews` 的「同一使用者對同一餐廳只能有一筆 active review」
  無法用 DB unique constraint 表達，改用交易——把現有 active review 改成 hidden 再建立新的，
  等於「重新評論＝覆蓋上一筆」並保留歷史。併發安全靠 InnoDB REPEATABLE READ 對
  `(user_id, restaurant_id, status)` 索引範圍的隱含 next-key lock，不需要額外顯式鎖。
- `GET /restaurants/{id}/reviews`（無需登入，只顯示 `status=active`，cursor 分頁）／
  `POST /restaurants/{id}/reviews`（`CreateReviewRequest` 驗證 rating 1~5）。
- `POST /restaurants/{id}/reports`（`CreateRestaurantReportRequest` 驗證 `type` 是文件列的
  7 種 enum 值之一）。
- `ReviewPolicy`／`RestaurantReportPolicy`（`create()` 目前都只要求已登入，沒有其他授權維度——
  審核／擁有者專屬的判斷留到有對應端點時再加）；`app/Http/Controllers/Controller` 補上
  `AuthorizesRequests` trait（Laravel 11 預設骨架的空 base controller 沒有這個，`$this->authorize()`
  要靠它才能用）。
- 實測全流程（真打 HTTP）：未登入看評論列表（空）→ 無 token 寫評論 401 → rating 超出範圍 422 →
  第一次評論成功 → 同使用者對同餐廳第二次評論 → 列表只剩最新一筆 active（舊的變 hidden，
  驗證覆蓋邏輯真的生效，不是憑程式碼讀出來的推測）→ 回報成功 → 回報 `type` 不合法 422 →
  無 token 回報 401。

**過程中抓到並修掉 1 個真的 bug：**

`POST /restaurants/{id}/reports` 一開始固定回 403「This action is unauthorized.」，即使
`RestaurantReportController` 明確呼叫了 `$this->authorize('create', RestaurantReport::class)`
且對應 Policy 的 `create()` 寫死回 `true`。根因：Laravel 的 Policy 自動發現慣例是
`{Model 類別名稱}Policy`——`App\Models\RestaurantReport` 對應的是 `App\Policies\RestaurantReportPolicy`，
不是我原本取名的 `ReportPolicy`，命名對不上，Laravel 找不到 Policy 時預設視為未授權。
改檔名／類別名稱成 `RestaurantReportPolicy` 後重測通過。

**未完成 / 等待確認：**

- Review／Report 都還沒有 Feature test，繼續靠手動 curl 驗證，之後要補。
- `restaurant.rating`／`rating_count` 沒有在寫入 review 時同步更新——依 `docs/database.md` 設計，
  這是快取欄位，由未來 Phase 6/7 的 `RecalculateRestaurantRatingJob` 批次更新，Phase 5 範圍
  只負責把 review 寫進去。
- Admin 審核 report（approve/reject、`reviewed_by`／`reviewed_at`）尚未實作，`docs/api.md`
  端點清單本來就沒列出對應 API，留給未來若有 Admin 面板時再處理。

## 2026-08-24 — Feature Test 補完

**完成：**

- 獨立測試資料庫 `veggiemap_testing`（同一個 Docker MySQL container，不碰 `veggiemap` 開發資料）：
  `CREATE DATABASE` + `GRANT ALL` 給既有 `veggiemap` 使用者。schema 用了 MySQL 專屬空間函式
  （`POINT`／`ST_Distance_Sphere`／`MBRContains`，見 `RestaurantRepository`），sqlite 跑不起來，
  所以沒有走常見的「測試用 sqlite in-memory」路線。`phpunit.xml` 直接把 `DB_*` env 硬指到這個庫，
  `RefreshDatabase` 每個測試方法都在交易裡跑完就 rollback。**這是手動一次性設定，新環境／CI
  要跑這個測試套件前得先手動建這個庫**（沒有寫成 migration/setup script，見「未完成」）。
- 6 個 Feature test 檔案（`tests/Feature/Api/`）涵蓋目前所有端點：`RestaurantTest`（列表／篩選／
  半徑搜尋距離排序／cursor 分頁翻頁不重複不漏／詳情／404）、`LookupTest`、`AuthTest`（含
  Phase 4 抓到的 role null／guest 401 兩個 regression）、`FavoriteTest`（含冪等行為）、
  `ReviewTest`（含覆蓋舊評論邏輯）、`RestaurantReportTest`（含 Phase 5 抓到的 Policy 命名
  regression）。共 30 個測試、82 個 assertion，全綠。
- 寫測試過程中兩個一開始失敗的案例，深入排查後確認**都是測試假象、不是產品 bug**：
  1. `logout` 測試：同一個 PHPUnit test method 內連續打兩次 HTTP 請求，共用同一個 app 容器，
     Sanctum 的 `RequestGuard` 會把第一次解析出的 user 快取在物件屬性上，不會因為 DB 裡的
     token 被刪就重新查——這只在單一 process 的測試環境發生（真實環境每個請求是獨立
     PHP-FPM process，Phase 4 手動 curl 已經驗證過 logout 後續請求真的會 401）。修法：
     兩次請求之間呼叫 `app('auth')->forgetGuards()` 清快取，不是改程式碼。
  2. `distance_meters` 型別斷言：JSON 編碼把整數值的浮點數（例如 `30.0`）序列化成 `30`，
     `json_decode` 讀回來是 PHP int——這是 JSON 編碼慣例，不是資料算錯。斷言改用
     `assertIsNumeric` 而非 `assertIsFloat`。

**未完成 / 等待確認：**

- 建測試庫是手動下的 SQL，沒有寫成腳本／文件化到 README（README 本身還是 Laravel 預設內容，
  留給 Phase 11）。之後要接 CI 的話，這個「先手動建 `veggiemap_testing`」的步驟必須自動化，
  否則 CI 環境跑不起來。
- 只覆蓋 Feature/HTTP 層行為，沒有 Unit test 覆蓋 `RestaurantRepository::boundingBoxPolygon()`
  這種純計算邏輯，也沒有測試 `ReviewService` 併發競態（需要真的並行請求才能驗證 next-key
  lock 有沒有效，目前只驗證了「循序覆蓋」邏輯）。

## 2026-08-24 — Phase 6: Rating／Confidence Score 批次計算 Job

**完成：**

- `config/vegetarian.php`：`verification_weights`（`restaurant_claim`／`menu_verified`／
  `user_report`／`photo_verified`／`external_source`／`admin_verified` 六種驗證類型的分數），
  `docs/database.md` 明確要求 `restaurant_verifications.score` 要對應這份 config，不寫死在程式碼。
- `VerificationService::record()`：集中管理「寫入一筆驗證紀錄時分數怎麼決定」的邏輯（查 config），
  供未來寫驗證紀錄的呼叫端共用。**目前還沒有任何 HTTP 端點會呼叫它**——餐廳自主認領／Admin
  後台／Phase 8 的 `restaurants:sync` 外部資料匯入都還沒實作，先把邏輯定義好等之後接。
- `RecalculateRestaurantRatingJob`：算某餐廳所有 `status=active` 的 review 平均分數與筆數，
  更新 `restaurants.rating`／`rating_count` 快取欄位。掛在 `ReviewService::submit()` 尾端，
  每次評論異動（新增或覆蓋）後觸發。
- `CalculateRestaurantScoreJob`：加總某餐廳所有未過期（`expires_at is null or > now()`）的
  `restaurant_verifications.score`，封頂 100，upsert 進 `restaurant_confidence_scores`。
- 兩支 Artisan 批次指令：`restaurants:recalculate-ratings`／`restaurants:calculate-scores`，
  chunk 過全部餐廳逐一 dispatch，用於 backfill／之後接 cron。
- **關鍵設計決定（不是隨口做的）**：兩個 Job 都實作 `ShouldQueue`，但呼叫端一律用
  `dispatchSync()` 而不是 `dispatch()`。原因：這個專案目前沒有跑 queue worker（`QUEUE_CONNECTION=redis`，
  Phase 1 就記錄過 Horizon/Sail 之類的套件還沒裝），如果真的呼叫 `dispatch()` 丟進 Redis 佇列，
  沒有 worker 消化的話 rating／confidence score 就永遠不會更新——會變成一個「程式碼看起來
  對、實際上什麼都沒發生」的死路徑。等之後真的架了 queue worker，把 `dispatchSync` 改回
  `dispatch` 即可，Job 類別本身不用動。
- 實測：
  - 6 個新 Feature test（`RecalculateRestaurantRatingJobTest`／`CalculateRestaurantScoreJobTest`）：
    hidden review 不計入平均、零 active review 歸零、API 送出評論後 rating 真的更新、
    過期驗證不計分、加總封頂 100、`VerificationService` 正確查表。全部通過（連同既有測試共
    36 個、92 個 assertion）。
  - 在**非測試的 dev 資料庫**上也真打過一次 HTTP（送出評論後 `GET /restaurants/1` 的 rating
    立即反映）＋跑過兩支 artisan 指令（`restaurants_confidence_scores` 表 20 筆全部 upsert 成功），
    確認 `dispatchSync` 這個決定在真實環境（不只測試環境的 sync queue driver）真的有效。

**未完成 / 等待確認：**

- 沒有任何端點會真的建立 `restaurant_verifications` 紀錄，所以目前所有餐廳的 confidence score
  都是 0——這不是 bug，是「分數計算管線已經打通，但分數的資料來源（自主認領／Admin／
  外部匯入）還沒做」的誠實現況，等 Phase 8（`restaurants:sync`）或 Admin 功能接上才會有非零值。
- 沒有排程（`routes/console.php` 的 schedule）自動跑這兩支批次指令，目前只能手動執行。

**追加（同一輪，使用者已授權後續建議自動採用）：**

`docs/api.md` 端點清單當初沒列 `confidence_score`，是文件遺漏——已經補進
`RestaurantResource`（`GET /restaurants/{id}` 詳情頁 eager load `confidenceScore` 關聯；列表頁
沒有帶，避免每筆多一次額外查詢的成本，且列表頁场景用不太到這個欄位）。測試與真實 HTTP
都驗證過會正確回傳（沒有分數紀錄時是 `null`，跑過批次指令後是實際數字）。

## 2026-08-24 — Phase 8: `restaurants:sync` 外部資料匯入

**完成：**

- Adapter Pattern（`docs/architecture.md`）：`RestaurantProviderInterface` + `RestaurantData`／
  `BoundingBox` value object，`OsmRestaurantProvider`（真的呼叫 Overpass API，30s timeout、
  429 退避重試、寫 `ExternalApiLog`）／`MockRestaurantProvider`（讀
  `storage/app/mock/restaurants.json` fixture，Overpass 斷線或本機無網路時的保底）。
  `AppServiceProvider` 依 `EXTERNAL_API_RESTAURANT_PROVIDER`（預設 `mock`，避免開發/測試環境
  不小心打到真的 Overpass）綁定介面。
- `RestaurantSyncService`：對每筆匯入資料——用 `(source, source_id)` upsert（重跑同一批不會
  產生重複列，已實測驗證冪等）、掛 diet_types、「同名＋距離 <100m 視為可能重複」兩筆都標記
  `is_possible_duplicate`（不自動合併/刪除，見 `docs/database.md`）、透過 `VerificationService`
  建立 `external_source` 驗證紀錄、`dispatchSync` 觸發 `CalculateRestaurantScoreJob`——這是
  Phase 6 那條「confidence score 目前全部是 0，因為沒有資料源會寫入 verification」的缺口，
  Phase 8 補上後分數管線才真正跑得起來。
- `php artisan restaurants:sync --bbox=minLat,minLng,maxLat,maxLng [--provider=mock|osm]`：
  `--bbox` 必填（不給明確錯誤訊息，不讓人一次不小心撈全台灣），`--provider` 可覆蓋 config
  做單次測試。
- 5 筆 fixture 資料（`storage/app/mock/restaurants.json`，台北/台中/高雄各一到三家，含
  `diet_codes`），讓 mock provider 開箱即可匯入非空結果。
- 實測（真打 command，非只讀程式碼）：
  - mock provider 匯入 5 筆成功，diet_types／verification／confidence score 全部正確寫入
    （用 `mysql --default-character-set=utf8mb4` 直接查表驗證，中途發現 client 預設連線
    charset 沒設會讓中文查詢條件對不上，這是查證方式的坑不是資料的坑）。
  - 冪等性：同一批 fixture 再跑一次，`created=0／updated=5`，`restaurants` 總數沒有變多。
  - Dedup：手動塞一筆跟既有餐廳同名同座標（不同 `source_id`）的資料，兩筆都被標記
    `is_possible_duplicate=1`；同名但距離遠（跨城市）的則不會誤標。
  - 7 個新 Feature test（`RestaurantSyncServiceTest`，含用匿名類別實作
    `RestaurantProviderInterface` 做的單元化測試，不依賴共用 fixture 檔內容），加上既有測試
    共 43 個、108 個 assertion，全綠。

**過程中抓到並修掉 1 個真的 bug：**

`Str::slug()` 對純中文名稱（例如「清心蔬食」）音譯不出任何 ASCII 字元，回傳空字串——實測
匯入後所有中文餐廳名的 slug 全部撞成同一個 fallback（`restaurant-2`／`restaurant-3`…），
`docs/database.md` 講的「slug 供人類看得懂的 URL 用」的設計目的完全失效。這對台灣餐廳平台
是會影響所有資料的系統性問題，不是邊角案例。修法：轉不出 ASCII 字元時退回用
`{來源}-{來源ID}`（例如 `osm-mock-node-1001`）當種子，每家餐廳至少有不同、可追溯的 slug。
寫了對應 regression test（`slug_falls_back_to_source_seed_...`）。

**未完成 / 等待確認：**

- `NominatimGeocodingProvider`（使用者輸入地址轉經緯度）**沒有實作**——`docs/api.md` 的端點
  清單裡本來就沒有「地址搜尋」這條 API（現有 `GET /restaurants` 直接吃 `latitude`/`longitude`
  數字），這部分屬於範圍外，不是漏做。
- Circuit breaker（`docs/external-apis.md` 提到「連續 N 次失敗後停止」）目前沒有實作：一次
  `restaurants:sync` 呼叫只打一次 Overpass API（一個 bounding box = 一次請求），單次呼叫內
  沒有「連續失敗」的情境可以觸發斷路器。這個概念要等未來做「一次排程掃多個 bounding box」
  的批次排程器時才有意義，先誠實記下不是忘記做。
- 沒有排程自動跑 `restaurants:sync`，目前只能手動執行（跟 Phase 6 的批次計算指令一樣）。
- `restaurants:sync --provider=osm` 沒有打過真的 Overpass API 驗證（只測過 mock provider）——
  `OsmRestaurantProvider` 的 HTTP 呼叫／重試／`ExternalApiLog` 寫入邏輯目前只靠程式碼審視，
  沒有實際對外流量驗證過，之後要跑一次真的匯入才能確認。

## 2026-08-24 — Phase 7: Admin 審核（report／review）

**範圍先講清楚：`docs/api.md` 的端點清單原本完全沒列 Admin 相關端點**，這個 Phase 是我自己
依 `restaurant_reports`／`reviews` 表已經有的 `reviewed_by`／`reviewed_at`／`status` 欄位
（見 `docs/database.md`）反推設計出來的，不是照抄某份既有規格，設計決定列在下面。

**完成：**

- `RestaurantReportPolicy::review()`／`ReviewPolicy::moderate()`：`$user->isAdmin()` 判斷，
  非 admin 一律 403（沿用既有的 Policy 自動發現機制與 `ApiExceptionRenderer` 錯誤格式）。
- `GET /api/v1/admin/reports`（預設只列 `pending`，可用 `?status=` 切換）／
  `POST /api/v1/admin/reports/{id}/approve`／`POST /api/v1/admin/reports/{id}/reject`：
  只更新這筆回報自己的 `status`／`reviewed_by`／`reviewed_at`，**刻意不會**反過來自動改動
  被回報的餐廳資料（例如 `type=closed` 不會自動把 `restaurant.status` 改成 `inactive`）——
  文件沒定義這種連動規則，寧可讓 Admin 自己另外處理，也不要憑空猜一個沒人要求的自動化行為。
  重複審核已審核過的回報回 422，不會覆蓋歷史。
- `GET /api/v1/admin/reviews`（可用 `?status=`／`?restaurant_id=` 篩選，看得到 `hidden` 的）／
  `POST /api/v1/admin/reviews/{id}/hide`：隱藏後立刻 `dispatchSync(RecalculateRestaurantRatingJob)`，
  跟 `ReviewService::submit()` 用同一套「沒有 queue worker，用 dispatchSync 保證真的生效」的邏輯。
  重複隱藏已隱藏的評論回 422。
- 實測（真打 HTTP，非 admin／admin 兩種身分都測過）：
  - 建了一個真實帳號、手動在 DB 把 `role` 改成 `admin`（沒有 Admin 帳號建立/晉升的 API，
    這本來就不在任何文件範圍內，MVP 靠手動 SQL 是合理的）。
  - 非 admin 打 `/admin/reports`／approve／`/admin/reviews`／hide 全部正確 403。
  - Admin 建立→列出→approve→再 approve 一次（422，不會覆蓋）全程正確；`reviewed_by` 正確記成
    審核者的 user id。
  - 送出評論（rating 從 0/0 變 1/1）→ Admin 隱藏該評論 → `GET /restaurants/{id}` 的 rating
    立刻掉回 0/0，證明 `RecalculateRestaurantRatingJob` 真的被觸發且拿到正確的最新資料。
  - 11 個新 Feature test（`tests/Feature/Api/Admin/`），加上既有測試共 54 個、129 個
    assertion，全綠。

**未完成 / 等待確認：**

- 沒有「Admin 帳號建立／晉升」的 API 或指令——目前只能手動改 DB。這對正式營運不可接受，
  但 MVP／Portfolio Demo 範圍先不做，之後若要做，合理的形式會是一支 Artisan command
  （例如 `users:promote {email}`）而不是公開 API。
- `restaurant_reports` 的審核結果目前完全不影響被回報的餐廳本身（見上面設計決定）——如果
  之後要接「approve 後自動處理餐廳資料」的規則，需要先定義清楚每種 `type` 該對應什麼動作，
  不是這個 Phase 該猜的範圍。
- Admin review 列表沒有像 `/restaurants` 那樣支援 `sort`／`keyword`，只有基本狀態/餐廳 id
  篩選——Admin 審核佇列的資料量級跟公開列表不同，先做最小可用版本。

## 現況總覽（2026-08-24 收尾）

（此段為 2026-08-24 收尾當下的舊快照，Phase 8.5／9 完成後現況見下方各自的 Phase 記錄與
[todo.md](todo.md)）Phase 0～8 全部完成並實測：架構／文件、Laravel＋Docker 專案初始化、13 張表
migration＋Model＋Factory＋Seeder、`/restaurants`（含半徑搜尋兩段式查詢＋cursor 分頁）、
`/diets`／`/features`、Sanctum 認證＋收藏、評論／回報、Rating／Confidence Score 批次計算 Job、
`restaurants:sync` 外部資料匯入（Overpass／Mock Provider）、Admin 審核 report／review。
54 個 Feature test、129 個 assertion，全綠。

## 2026-08-24 — Phase 8.5：地址搜尋（Geocoding）

**完成：**

- `GeocodingProviderInterface` + `GeocodedPlace` value object（`app/Services/External/`），
  跟 Phase 8 的 `RestaurantProviderInterface` 同一套 Adapter Pattern。只有 Nominatim 一種
  provider（`docs/external-apis.md` 已核准，沒有 mock/real 切換需求），直接在
  `AppServiceProvider` 綁死 `NominatimGeocodingProvider`，不做用不到的抽象。
- `NominatimGeocodingProvider`：呼叫 Nominatim `/search`，5s timeout、429 退避重試（`throw:false`，
  非 429 的失敗回應正常回傳而不是被 Laravel HTTP client 的 retry 機制強制丟例外）、寫
  `ExternalApiLog`。
- `GET /api/v1/geocode?q=關鍵字`：`GeocodeRequest` 驗證、`Cache::remember('geocode:'.md5($q), 1天, ...)`
  擋重複查詢字串，避免撞 Nominatim 每秒 1 次請求的政策限制。失敗時回
  `{"success":true,"data":[]}`，不讓地圖首頁因外部服務掛掉而整個壞掉。
- 4 個 Feature test（`tests/Feature/Api/GeocodeTest.php`，`Http::fake` 模擬 Nominatim，不真打
  外部 API）：正常回傳、缺 `q` 回 422、Nominatim 失敗回空陣列且正確記 `error_code`、重複查詢
  真的只打一次 Nominatim（cache 生效）。加上既有測試共 58 個、141 個 assertion，全綠。

**過程中抓到並修掉 1 個真的 bug：**

`->retry($times, $sleep, $when)` 的第 4 個參數 `$throw` 預設是 `true`——代表即使 `$when`
判斷不該重試（例如收到 503 而非 429），Laravel HTTP client 還是會在耗盡重試次數後把
非 2xx 回應強制轉成例外丟出來。寫 Feature test 模擬 503 時才發現：預期回應該正常帶
`error_code=HTTP_503`，實際卻是被外層 `catch (\Throwable)` 接住、記成籠統的
`error_code=RequestException`，功能上仍會 fallback 成功但記錄不準確。修法：`retry()` 加
`throw: false`，只在真的觸發 429 重試時才允許中途丟例外驅動 retry 迴圈本身，其他失敗回應
正常回傳讓程式自己判斷 `successful()`。

**實測（真打外部 Nominatim API，非 mock）：**

打 `GET /api/v1/geocode?q=台中一中街` 一開始回空陣列，查 `external_api_logs` 發現
`status=403`——不是程式碼邏輯錯，是 `.env` 的 `EXTERNAL_API_NOMINATIM_USER_AGENT` 預設值
`"VeggieMap/1.0 (contact: you@example.com)"` 帶著 `example.com` 這種常見教學範例網域字串，
被 Nominatim 的防護機制直接擋掉（用 `curl` 直接對 Nominatim 測試同一組 User-Agent 字串重現，
排除是本地網路或程式碼問題）。改成 `VeggieMap/1.0 (+https://github.com/thothawei/veggie-map)`
後重打，成功拿到「台中一中, 育才街...」等真實結果，`external_api_logs` 記到 `status=200`。
細節見 [api.md](api.md) 的「踩過的坑」段落。

**未完成 / 等待確認：**

- `docs/openapi.yaml` 仍未產出（Phase 11 範圍），`/geocode` 先只記在 `docs/api.md`。
- 前端串接（輸入框 → call `/geocode` → 移動地圖）留給 Phase 9，這個 Phase 只做後端 API。

## 2026-08-24 — Phase 9：Vue 3 + Leaflet 前端

**完成：**

- `npm install` 加 `vue`／`vue-router@4`／`pinia`／`leaflet`／`leaflet.markercluster`
  （`vue-router@5` 需要 vite 7/8，跟現有 vite 6 衝突，鎖 4.x）、`typescript`／`vue-tsc`／
  `@vitejs/plugin-vue`／`@types/leaflet`（`typescript@7` 是最新版但 `vue-tsc@3.3.11` 還不支援
  它移除 `./lib/tsc` export 的新版佈局，鎖回穩定的 `typescript@5.9.3`）。
- 架構決定：SPA 不是獨立跑在 5173 的專案，而是延續 Laravel 既有的 `laravel-vite-plugin`
  整合方式——`resources/views/app.blade.php` 當 shell，`routes/web.php` 用
  `Route::view('/{any}', 'app')->where('any', '.*')` 把所有非 `/api`、非 `/up` 的路徑交給
  Vue Router（history 模式）自己決定畫面。`npm run dev` 只負責資產編譯與 HMR，實際頁面
  一律從 `http://localhost:8080/` 進，不是 5173——比總 prompt 原本設想的「前端獨立跑在
  5173」更貼近這個專案已經是 Laravel 全端專案的事實，也不需要另外處理 CORS 讓頁面本身跨
  origin（`config/cors.php` 還是加了，給 API 呼叫本身用，也方便未來真的要拆前後端時直接用）。
- 頁面：`HomeView`（地圖首頁）、`RestaurantListView`、`RestaurantDetailView`、`LoginView`、
  `RegisterView`、`FavoritesView`、`ProfileView`、`AdminView`（reports/reviews 審核）。
  路由用 `:id` 不是總 prompt 寫的 `:slug`——後端 `GET /restaurants/{restaurant}` 是 id-based
  route model binding（`docs/api.md` 早就這樣記錄），沒有 slug 查詢能力，前端路徑照實際 API
  能力設計，不假裝支援不存在的查詢方式。
- `RestaurantMap.vue`：Leaflet + `leaflet.markercluster`，`moveend` 事件驅動依 bounds 撈餐廳
  （`HomeView` 用 bounds 中心點＋對角線距離的一半當半徑，直接餵給既有的 `/restaurants` 半徑
  搜尋，不用另開一支「依 bounds 查」的 API）、目前位置（`navigator.geolocation`）、marker
  popup、點擊導到詳情頁。
- `SearchBox.vue`：串 Phase 8.5 的 `/geocode`，選取候選地點後地圖 `flyTo`。
- `FilterDrawer.vue`：串 `/diets`／`/features`，`defineModel` 雙向綁定篩選條件。
- Pinia：`stores/auth.ts`（token 存 localStorage、`fetchCurrentUser`／`login`／`register`／
  `logout`）、`stores/favorites.ts`。Axios client（`api/client.ts`）攔截器自動帶 Bearer token。
- Router guard：`meta.requiresAuth`／`requiresAdmin` 未登入導去 `/login?redirect=`。

**過程中抓到並修掉的問題：**

1. `vue-tsc --noEmit` 直接崩潰（`ERR_PACKAGE_PATH_NOT_EXPORTED`）——根因是 npm 預設裝到最新的
   `typescript@7.x`，這個專案跑的當下 `vue-tsc` 生態還沒跟上 TS7 的新 export 佈局，不是
   我的程式碼問題。鎖回 `typescript@^5.9` 解決。
2. `body` 沒有明確設 `background`，在瀏覽器實測時整頁背景是黑的（繼承宿主環境的深色主題），
   不是 CSS 邏輯錯誤但會在不同瀏覽器/系統主題下表現不一致——加上明確的 `background: #fff`。
3. Template 裡 `@blur="() => setTimeout(...)"` 型別檢查不過（Vue 元件實例代理沒有全域
   `setTimeout`），改成具名方法呼叫 `window.setTimeout`。

**實測（真的開瀏覽器點，不只 build 過）：**

`npm run build`（含 `vue-tsc --noEmit`）過、`npm run dev` 起 Vite，瀏覽器開
`http://localhost:8080/` 逐條走過：地圖載入台中市 20 家種子餐廳並正確分群成 cluster、點開
cluster 看到個別 marker、點 marker 跳轉到 `/restaurants/{id}` 詳情頁且內容（名稱/評分/地址/
菜單/diet標籤/features標籤）正確；搜尋框輸入「台北車站」→ 真的打 Nominatim 拿到候選清單 →
點選後地圖飛到台北（附近沒有種子餐廳，正確顯示空結果，不是 bug）；註冊帳號 → nav 正確切換
成已登入狀態 → 進入某餐廳詳情頁按「加入收藏」即時變成「已收藏」→ 送出評論後評分立即從
0.0(0) 變成 5.0(1)，證明前端打的 API 跟 `RecalculateRestaurantRatingJob` 的
`dispatchSync` 串起來了 → `/favorites` 頁面正確列出剛收藏的餐廳。全程瀏覽器 console 無錯誤。
`/restaurants` 列表頁篩選 chip 與搜尋框也正常。

**未完成 / 等待確認：**

- `AdminView` 只用程式碼推導 admin API 呼叫方式驗證過（unit 層級沒問題），沒有實際拿一個
  admin 角色帳號在瀏覽器裡點過核准/駁回/隱藏——這個決定是因為要手動改 DB 把某帳號設
  `role=admin` 才能測，範圍上跟 Phase 7 當初手動測試 admin API 的方式一致，但沒有在這次
  一併重新過一次瀏覽器驗證，下次有動 Admin 相關程式碼時要記得補測。
- 沒有 Vitest／Playwright 這類前端自動化測試，目前全靠這次的手動瀏覽器驗證，見
  `docs/todo.md` Phase 10。
- Mobile responsive（總 prompt 要求的地圖 UX 項目之一）目前只用桌面尺寸驗證過，沒有實際
  切到手機視窗檢查版面。
- Router guard 的 `requiresAdmin` 判斷依賴 `auth.user` 已經載入完成；如果使用者帶著舊
  token 直接刷新進 `/admin`，`fetchCurrentUser()` 還沒 resolve 前那一瞬間會先被導回首頁，
  是已知的競態限制，MVP 範圍先不特別處理（重新整理後手動點一次連結即可正常進入）。

## 2026-08-24 — Phase 10：補測試缺口

**完成：**

- `tests/Unit/RestaurantRepositoryBoundingBoxTest.php`：用 `ReflectionMethod` 直接測
  `boundingBoxPolygon()`（private 純數學方法，不碰 DB，用純 `PHPUnit\Framework\TestCase`
  不啟動 Laravel framework）。4 個測試：WKT 封閉環格式、赤道附近的角座標數值、高緯度經度
  跨幅確實變寬（地球是球體不是平面格線）、極點附近除以零防呆真的不會出 NAN/INF。
  自我驗證過：故意把 `lngDelta` 改錯（等於 `latDelta`，忽略緯度修正）重跑，
  「高緯度經度跨幅變寬」那條測試真的紅了，確認測試有真的在測東西，不是恆真斷言。
- `docs/todo.md` 原本列的「Unit test：距離計算相關純邏輯」查證後發現不存在對應目標——
  這個專案的距離計算 100% 在 SQL 端（`ST_Distance_Sphere`），沒有獨立的 PHP 距離公式可以
  抽出來單元測試，唯一的純 PHP 幾何邏輯就是上面測掉的 bounding box。誠實記錄查證結果，
  不硬湊一個沒意義的測試。
- `scripts/setup-test-db.sh` + `docker/mysql/init/01-create-test-database.sql`：把
  「Feature Test 補完」那次手動下的 SQL 腳本化。全新 volume 靠後者（MySQL image 只在
  volume 第一次初始化時執行 `docker-entrypoint-initdb.d`），已存在的 volume（多數本機
  開發環境）不會自動重跑，所以前者是隨時可以重跑的等效版本，也是未來 Phase 12 CI 要用的
  那一步。實測：對已經手動建過的既有測試庫重跑，`CREATE DATABASE IF NOT EXISTS` 正確
  no-op、`migrate --force` 正確回報 `Nothing to migrate`。
- `tests/Feature/ReviewServiceConcurrencyTest.php` + `tests/Support/hold_review_lock.php`：
  第一個真正讓兩個交易重疊的測試（Phase 5 的 Feature Test 補完只驗證過循序覆蓋）。
  背景用獨立 PHP process（`Illuminate\Support\Facades\Process`）+ 原生 PDO（不能用
  `RefreshDatabase`——它把整個測試包在一個交易裡，另一條連線看不到未 commit 的資料）
  故意撐住鎖，前景呼叫真正的 `ReviewService::submit()`，斷言：(1) 前景真的被鎖卡住等待
  （測 elapsed time，不是只看結果）、(2) 併發下仍然只有一筆 active review。
- **這個測試在開發過程中抓到一個真的 bug**：`ReviewService::submit()` 原本的程式碼註解
  宣稱靠 InnoDB 的 next-key lock 就能保證併發安全，但實測發現兩個交易對同一個空 index
  range 各自取得 gap lock 後都想 `INSERT` 進那個 gap 時，InnoDB 會判定成 deadlock
  （`1213 Deadlock found`）直接丟例外中止其中一個交易——不是誰乖乖排隊等誰。原本的
  `DB::transaction($fn)` 沒有帶重試次數（預設 1 次），代表使用者端在真實併發下有機會
  收到 500 而不是優雅地稍等一下。修法：`DB::transaction($fn, 3)`，帶 Laravel 內建的
  deadlock 自動重試。
- **測試本身也做了自我驗證，且過程中發現结果比預期複雜**：拔掉 `, 3` 重跑 8 次，
  沒有一次重現 deadlock 例外——因為背景測試腳本自己也有重試迴圈（一開始加是為了讓
  背景 process 不要一遇到 deadlock 就整個腳本崩潰），會默默吸收掉大部分的 deadlock，
  使得這個測試對「前景是否有重試」這件事本身不是每次都能觸發判定。誠實記錄：這個測試
  可靠驗證的是「兩個真的重疊的交易之後，資料庫裡永遠不會出現兩筆同時 active 的 review」
  這個核心不變量（每次都驗證到），但不是每次都能重現最一開始抓到的那個 deadlock
  interleaving 本身——那個 bug 是靠人工重跑同一個測試多次、配合關掉背景腳本的重試
  才穩定重現、修掉、驗證過的，不是這支測試每次自動保證會抓到。`DB::transaction($fn, 3)`
  這個修法本身是正確且符合 Laravel 慣例的防禦性修正，即使 regression test 對這一項的
  保護力不是 100%。
- 實測：全部 63 個測試（含新增 5 個）、179 個 assertion 全綠；`ReviewServiceConcurrencyTest`
  連續跑 3 次穩定通過。

**額外處理（Phase 9 留下的兩項缺口，趁這次一併驗證掉）：**

- **重現並修掉 Router guard 的競態 bug**：真的拿剛才手動改成 `role=admin` 的帳號，
  瀏覽器直接硬導航到 `/admin`——確認真的會被導回首頁，不是猜測。根因比 Phase 9 記錄的
  更深一層：一開始只把 `app.mount()` 延到 `fetchCurrentUser()` resolve 之後還是沒用，
  因為 Vue Router 4 的初始導航是在 `app.use(router)` 當下就觸發，不是等 `app.mount()`；
  真正有效的修法是連 `app.use(router)` 本身都要延後到 `fetchCurrentUser()` resolve 之後
  （`resources/js/main.ts`）。修完後同一個瀏覽器 session 重新硬導航到 `/admin`，正確停留
  在管理後台，列出待審核回報／評論。
  - 順便走了一次完整 Admin 流程（先前只有程式碼審視過）：核准一筆回報（消失於待審核
    清單）、隱藏一則評論（`GET /restaurants/{id}` 的 rating 立刻掉回 0.0(0)，證明
    `RecalculateRestaurantRatingJob::dispatchSync()` 真的被 Admin 動作觸發）。
- **抓到並修掉真的 Mobile responsive bug**：切到 375px 寬瀏覽器，`document.documentElement`
  的 `scrollWidth`（437px）大於 `clientWidth`（375px）——不是憑感覺猜的，是量出來的真實
  橫向溢出。根因：`.app-header nav` 跟 `HomeView` 的 `.hero-controls` 都是沒有
  `flex-wrap` 的 flex row，導覽列連結跟「搜尋框＋使用目前位置」按鈕在窄螢幕硬擠成一行。
  修法：兩處都加 `flex-wrap: wrap`，`.app-header`／`.hero` 的 padding 縮小、
  `white-space: nowrap` 避免文字被硬折斷。修完後 `scrollWidth === clientWidth === 375`，
  首頁／餐廳列表／餐廳詳情／管理後台四個頁面都重新在 375px 寬下驗證過無橫向溢出。

**未完成 / 等待確認：**

- 前端仍然沒有 Vitest 元件測試或 Playwright E2E——這次新增的 `lib/geo.ts`（從
  `HomeView.vue` 抽出來的 Haversine 距離計算，原本是行內函式，抽出來才有辦法脫離
  Leaflet 地圖元件單獨測）是唯一的前端自動化測試，golden path 仍然靠手動瀏覽器驗證。
- `veggiemap_testing` 的建庫腳本化只驗證過「對已存在的資料庫重跑」，沒有實際刪掉整個
  Docker volume 從零驗證過 `docker-entrypoint-initdb.d` 那條全新 volume 路徑（會動到
  這台機器上其他人的開發資料，沒有必要冒這個風險去驗證一段邏輯很單純的 SQL）。

## 2026-08-24 — Phase 12：GitHub Actions CI

**完成：**

- `.github/workflows/ci.yml`：兩個平行 job。
  - **Backend**：`shivammathur/setup-php@v2`（PHP 8.2 + pdo_mysql/mbstring/bcmath/gd/zip）
    → 建 `.env` → `composer install` → `php artisan key:generate` → 建前端資產（見下方
    「過程中抓到的 bug」）→ `pint --test` → `phpstan analyse`（Larastan）→ 等 MySQL service
    container 就緒 → `migrate --force` → `php artisan test`。
  - **Frontend**：`actions/setup-node@v4`（Node 22）→ `npm ci` → `eslint` → `vue-tsc` →
    `vitest run` → `npm run build`。
- 新增 `larastan/larastan` + `phpstan.neon`（level 5）。第一次跑出 12 個錯誤，不是雜訊——
  `Restaurant`／`Review`／`RestaurantReport`／`RestaurantConfidenceScore` 的關聯方法回傳型別
  都只寫裸的 `BelongsTo`／`HasMany`／`HasOne`，沒有帶泛型，導致 PHPStan 完全看不穿
  `$this->restaurant->id` 這種透過關聯存取的寫法。補上 `@return BelongsTo<Restaurant, $this>`
  這類泛型 docblock 是全面性修正（不是只在出錯的地方局部繞過），順便也拿掉了
  `OsmRestaurantProvider`／`NominatimGeocodingProvider` 裡兩個多餘的 `?->`（`RequestException`
  的建構子簽章證明 `$response` 永遠不是 null，PHPStan 講對了）。修完 0 error。
- 新增 ESLint（flat config，`eslint-plugin-vue` + `@vue/eslint-config-typescript`）。
  抓到的都是真問題：一個沒用到的 import、四處 `catch (e: any)` 直接存取
  `e?.response?.data?.error?.message` 這種完全沒型別保護的寫法。改成
  `resources/js/lib/apiError.ts` 的 `extractApiErrorMessage`／`extractApiErrorFields`，
  用 axios 的 `isAxiosError` 做真正的型別窄化，不是隨便塞個 `unknown` 應付 lint。瀏覽器
  重新走過一次登入錯密碼的流程，確認改完之後錯誤訊息還是正確顯示，不是只有 type-check 過。
- 啟用 `pint --test` 前，先跑一次完整（非 `--test`）的 `pint` 格式化全專案——這個 CI gate
  一啟用就會抓到 16 個 Phase 0~8 留下來、從來沒被嚴格模式檢查過的既有風格問題。既然是我
  自己要加這道 CI gate，讓它第一次真的跑就失敗不合理，先修乾淨是前提不是額外加工。

**過程中抓到並修掉的 3 個 bug（第一次真的推上 GitHub Actions 跑才發現，本機 docker-compose
一直是綠燈，因為本機環境有這次 session 留下的殘餘狀態把問題蓋住了）：**

1. **`storage/app/mock/restaurants.json` 從來沒有真的進版控**——`storage/app/.gitignore`
   整個目錄用 `*` 擋掉，Phase 8 建立這個 fixture 時沒有 `git add -f`，這個檔案其實只存在
   於我的本機。任何人 fresh clone 這個 repo，`restaurants:sync --provider=mock` 都會匯入
   0 筆（`RestaurantSyncServiceTest` 期待 5 筆），因為 mock provider 讀不到不存在的檔案。
   這對一個履歷用的 Portfolio 專案是嚴重問題——demo 的保底路徑本身其實是壞的。修法：
   `.gitignore` 加 `!mock/`／`!mock/restaurants.json` 例外，`git add -f` 真的把檔案加進去。
2. **`GET /`（`tests/Feature/ExampleTest.php`）在全新 checkout 上會炸
   `ViteManifestNotFoundException`**——Phase 9 把 `/` 改成 Vue SPA shell（`app.blade.php`
   透過 `@vite` 載入資產），render 這個 view 需要 `public/build/manifest.json`。本機測試
   一直過是因為這個 session 稍早跑過 `npm run build`，`public/build/` 一直躺在本機沒清掉，
   蓋住了「後端測試其實依賴前端建置產物」這個真實耦合。修法：Backend CI job 在跑 Pint/
   PHPStan/PHPUnit 之前先 `npm ci && npm run build`（不是刪掉這個測試打發——`/` 本來就是
   這個專案实际的入口，測它回 200 是有意義的迴歸測試，跟 README「Local Development」講的
   「前端要 build 過整個 app 才能動」是同一件事）。用 `mv public/build /tmp && php artisan
   test tests/Feature/ExampleTest.php` 反向驗證過真的會紅、加回來會綠，不是憑錯誤訊息猜的。
3. **Vitest 在 CI 直接 Startup Error**：`You should not run the Vite HMR server in CI
   environments`——Vitest 預設吃 `vite.config.js`，裡面的 `laravel-vite-plugin` 偵測到
   CI 環境變數直接擋掉。單元測試根本不需要 Laravel 的資產管線，加一份獨立的
   `vitest.config.ts`（只保留 `vue()` plugin，不含 `laravel()`）解決，兩份設定互不干擾。
   本機用 `CI=true npm run test` 重現過失敗、加了 `vitest.config.ts` 之後重現過修好，
   不是照錯誤訊息裡的 `LARAVEL_BYPASS_ENV_CHECK=1` 提示照抄了事（那個做法只是關掉檢查，
   沒有解決「Vitest 不該吃到 Laravel 資產管線設定」這個根本問題）。

**實測：**

`git push` 後用 `gh run watch` 真的看完整整兩次 workflow 執行（不是寫完 yaml 就交差）：
第一次跑就在 100% 真實的全新 checkout 環境下抓到上面 3 個 bug；修完後第二次兩個 job
（Backend 1m13s／Frontend 19s）全綠，`gh run view` 確認 exit code 0。

**未完成 / 等待確認：**

- `gh` CLI 的 OAuth token 一開始沒有 `workflow` scope，push `.github/workflows/ci.yml`
  被 GitHub 拒絕；裝置授權碼（device code）前兩次都在使用者來得及操作前過期，第三次才
  成功——這不是我能自動解決的，純粹是使用者互動時間的問題，記錄下來避免下次以為是
  gh 指令本身有問題。
- CI 沒有 cache Composer/npm 依賴到 GitHub Actions cache（`actions/setup-node` 的
  `cache: npm` 有開，但 `shivammathur/setup-php` 沒接 Composer cache）——目前 Backend job
  1m13s 可接受，等 PR 數量變多、想省 CI 時間時再考慮加。
- `pull_request` trigger 存在，但這個專案目前沒有走 PR 流程（約定是直接 push `main`），
  沒有實際拿一個 PR 驗證過 `pull_request` 事件會不會正確觸發。

## 2026-08-24 — Phase 11：文件收尾（`docs/openapi.yaml`、`docs/observability.md`）

**完成：**

- `docs/openapi.yaml`：OpenAPI 3.0.3，涵蓋全部 20 支已實作端點（`docs/api.md` 表格 + 這次
  順便補上的 Phase 7 admin 端點）。內容直接對照 `routes/api.php`、各 `FormRequest` 的
  `rules()`、各 `Resource` 的 `toArray()` 產出，不是憑印象寫的——寫的過程中重新讀了一輪
  `CreateRestaurantReportRequest`／`DietTypeResource`／`FeatureResource`／
  `RestaurantReportResource`（一般使用者版，跟 Admin 版欄位不同）確認欄位。
- 真的用 `npx @redocly/cli lint` 跑過（不是只求 YAML 語法過），修了 3 個問題：
  1. `nullable: true` 沒有搭配真實 `type` 會被判定違反 OpenAPI 3.0 規範（跟 JSON Schema
     2020-12 的 `type: "null"` 混用是常見誤用，3.0.x 不支援後者）。
  2. `security` 屬性放進 `Authorization` header 的說明文字裡有未跳脫的冒號，YAML 直接
     解析失敗（`mapping values are not allowed here`）——純語法坑，跟 OpenAPI 語意無關。
  3. `info.license.name: MIT` 沒有配 `url` 會被 lint 標記；查證後這個 repo 根本沒有
     `LICENSE` 檔，等於是編了一個不存在的授權條款——不是補 url 敷衍過去，是直接把
     `license` 欄位整個拿掉，等使用者真的決定授權條款再補。
  跑到最後「Your API description is valid. 🎉」，剩下 13 個警告都是 `operation-4xx-response`
  這類建議性規則（例如 `/diets`／`/features` 這種完全沒有參數、不可能驗證失敗的端點，
  硬掰一個 4xx response 反而是說謊，沒有修）。
- `docs/observability.md`：刻意用「有 vs 沒有」的對照表收尾，不是寫一份看起來很完整
  的監控藍圖。查證過才寫的部分：`failed_jobs` 表確實存在（Laravel 11 骨架自帶），但因為
  Phase 6 的 `dispatchSync()` 決定，這張表目前實務上不會有資料——沒有含糊帶過，直接點名
  這是已知限制。API response time／cache hit-miss／DB 慢查詢追蹤三項都誠實標記未實作，
  沒有為了讓文件「看起來完整」就寫成已經做了。

**過程中的副產品：**

寫 OpenAPI 時對照 `routes/api.php` 才發現 `docs/api.md` 的端點清單表格從 Phase 5 之後
就沒更新過，漏了整個 Phase 7 的 Admin 端點（`/admin/reports`、`/admin/reviews` 等 5 條）。
順手補上，這是文件之間互相校對抓到的落差，不是這個 Phase 原本規劃要做的事。

**未完成 / 等待確認：**

- `docs/observability.md` 提到「/up 比 / 更適合當 health check smoke test」，但
  `tests/Feature/ExampleTest.php` 目前還是測 `/`（Phase 12 選擇用「backend job 先 build
  前端資產」解決，而不是換掉測試目標）——這個取捨已經在 Phase 12 記錄過，這裡只是文件
  互相對照時再次確認沒有遺漏，不是新發現的問題。
- OpenAPI 規格沒有跑正式的 contract test（Dredd／Schemathesis 那種自動化打真 API 驗證）。
  有手動用 `curl` 打過幾條代表性的端點交叉核對過（`GET /restaurants/{id}` 詳情頁、
  帶座標的半徑搜尋、`GET /diets`、`sort=distance` 缺座標的 422 錯誤格式），確認欄位跟
  規格寫的一致，包含 `distance_meters`／`confidence_score` 這種條件式欄位（`whenLoaded`／
  `when()` 不成立時是整個 key 消失，不是 key 存在但值是 null——這點規格的敘述文字已經
  講清楚，只是沒有用型別系統強制）。但沒有涵蓋全部 20 支端點，Admin 那幾支（需要 admin
  token）跟寫入類端點沒有逐一核對，這裡誠實記錄範圍。

## 2026-08-24 — Phase 13：部署文件

**完成：**

- `docs/deployment.md`：方案 A（EC2+RDS+ElastiCache）／方案 B（ECS Fargate，未來 scale
  用，只記錄架構方向不展開步驟）比較與選擇理由；方案 A 的完整步驟（RDS／ElastiCache
  佈建、EC2、production `.env` 覆寫清單、建置指令、HTTPS、admin 帳號建立、Queue
  Worker／排程建議、安全性、回滾策略）。
- 開頭先列「目前還不是 production-ready」的缺口對照表（沒有 Horizon、沒有
  `users:promote`、沒有排程、`CVE-2026-48019` 未處理、Nominatim 商業政策偏保留），
  每項標記「部署前要不要處理」，不是寫成一份看起來萬事俱備的文件。
- 重新真的跑了一次 `composer audit`（不是憑 Phase 1 記錄的舊印象），確認
  `CVE-2026-48019`（Laravel CRLF injection in default email rule）現況依然存在，
  修法還是升級到 12.60+/13.10+，寫進部署文件的「安全性：部署前必須處理」。

**未完成 / 等待確認：**

- 沒有實際執行任何 AWS 資源佈建或部署——沒有 credentials，也沒有使用者確認要花錢起
  infra，依照總 prompt 規則停在文件階段。
- 方案 B（ECS Fargate）只記錄架構方向，沒有寫逐步操作——這個專案目前的規模用不到，
  真的要用的時候再回來展開。

## 2026-08-24 — 補：`RuleBasedRecommendationService`（總體規劃第 29／30 節，Phase 0 就設計過但沒做）

**發現過程：** Phase 0 的 `docs/architecture.md`「AI 預留」段落早就寫好
`RecommendationServiceInterface`／`RuleBasedRecommendationService`／`config/recommendation.php`
的設計，而且明講「第一版只有 RuleBasedRecommendationService 實作」——這不是 AI/ML，是
MVP 範圍內的東西，「明確不做的事」那段排除的是 `Recommendation ML`（AI 版本），不是
Rule-based 版本本身。但 Phase 9 做前端首頁「推薦餐廳」時，直接在 `HomeView.vue` 用
`[...restaurants].sort((a,b) => b.rating - a.rating).slice(0,6)` 打發，從來沒有實作
過設計文件裡講的這個服務——是重新過一遍總體規劃 md 對照現有程式碼才抓到的落差。

**完成：**

- `config/recommendation.php`：六個分量權重（distance/rating/vegetarian_confidence/
  feature_match/popularity/freshness，對應總體規劃第三十節公式）、
  `max_features_expected`、`freshness_window_days`、`candidate_pool_size`。
- `RecommendationServiceInterface` + `RuleBasedRecommendationService`
  （`app/Services/Recommendation/`）：候選集合 `rank()`，每個分量正規化到 0~1 再加權，
  在候選集合內部排序（不是跨請求可比較的絕對分數，跟半徑搜尋的 distance 一樣是
  bounded-context 的相對值）。`AppServiceProvider` 綁定介面，未來換
  `AIRecommendationService` 只改綁定這一行。
- `RestaurantRepository::candidatesForRecommendation()`：復用既有 `search()` 的半徑搜尋，
  不是另開一套查詢邏輯，只是多 eager load 算分需要的 `dietTypes`／`features`／
  `confidenceScore`。
- `GET /api/v1/restaurants/recommended`（`RecommendedRestaurantRequest` 驗證
  latitude/longitude 必填），route 註冊在 `/restaurants/{restaurant}` **之前**——
  沒有排對順序的話 Laravel 會把 "recommended" 當成 route model binding 的 id 去查，
  這是新增靜態路徑段路由時的標準陷阱，寫的時候就注意到了。
- `RestaurantResource` 新增 `recommendation_score`（`when(isset(...))`，只有這支端點
  回應才會有這個欄位，跟既有 `distance_meters` 同一套模式）。
- `HomeView.vue` 的「推薦餐廳」改成真的打這支新端點（`Promise.all` 跟原本的 bounds
  搜尋並行），不再是前端隨便排序既有列表那幾筆。

**過程中抓到並修掉的問題（不是一次寫對）：**

1. `EloquentCollection::make($paginator->items())->load(...)` 一開始寫成
   `collect(...)->load(...)`——`Illuminate\Support\Collection`（`collect()` 回傳的型別）
   沒有 `load()` 方法，那是 `Illuminate\Database\Eloquent\Collection` 才有的，本機測試
   直接噴 `BadMethodCallException`，改用 `EloquentCollection::make()` 解決。
2. PHPStan（Larastan）誤判 `$restaurant->confidenceScore?->score` 的 `?->` 是多餘的
   （宣稱這個關聯永遠非 null），但這是 Larastan 對 eager-loaded `HasOne` 的已知限制，
   不是真的——`test_score_is_bounded_between_zero_and_one` 那個測試建立的餐廳完全沒有
   `RestaurantConfidenceScore` 紀錄，`?->` 拿掉的話那個測試會直接炸
   `Attempt to read property on null`，實測驗證過才敢用 `@phpstan-ignore` 蓋掉這條規則，
   不是看到報錯就無腦加 ignore。
3. `Restaurant` model 動態設定 `recommendation_score`（跟既有的 `distance` 一樣不是
   資料表欄位）在 PHPStan 底下被判定成「寫入未宣告屬性」——`distance` 沒被抓到是因為
   只有「讀」被放寬檢查，「寫」是不同規則；補 `@property float|null $recommendation_score`
   到 Restaurant model 的 class docblock 解決，跟既有 `$distance` 的註記方式一致。

**實測：**

- 3 個新的 `RuleBasedRecommendationServiceTest`（含「故意讓分數排序邏輯反過來，測試
  真的會紅」的自我驗證）+ 2 個新的 HTTP 層測試（`RestaurantTest`），加上既有測試共
  68 個、211 個 assertion，全綠。Pint／PHPStan 都乾淨。
- 真打 `curl http://localhost:8080/api/v1/restaurants/recommended?...`，回應正確依
  `recommendation_score` 遞減排序。
- 瀏覽器打開首頁，「推薦餐廳」區塊網路請求確認真的打到新端點（不是舊的前端排序），
  console 無錯誤。
- `docs/openapi.yaml` 補上這支端點與 `Restaurant.recommendation_score` 欄位，
  `npx @redocly/cli lint` 重新跑過仍然 0 error。`docs/api.md`／`docs/architecture.md`
  同步更新，`architecture.md` 的「AI 預留」標題從「不在 MVP 範圍」改成標明
  Rule-based 版本已實作，只有 AI 版本還沒做。

## 2026-08-24 — 補：Redis Search/Detail Cache + Rate Limiting（總體規劃第 16／17／42 節）

**發現過程：** 同一輪重新對照總體規劃 md 抓到的第二個落差，比 Recommendation 那個更嚴重——
「Redis Cache」跟「Rate Limiting」是總體規劃開頭第一段就列出的核心能力清單項目
（跟 RESTful API／MySQL／Queue 並列），第十六／十七節也明講要對 `/restaurants` 做
search cache（`restaurants:search:{hash}`，300s）／detail cache（`restaurant:{id}`，
600s）／清快取／Redis-based rate limiter。查證發現：**這兩項在這個專案裡完全是 0%
實作**——`grep` 全專案找不到任何 `throttle` middleware、找不到 `RateLimiter::for`，
Redis cache 只有 Phase 8.5 的 `GET /geocode` 一處在用，核心的 `/restaurants` 列表／詳情
每一次請求都是真的打 MySQL。這不是「還沒排到」的技術債，是履歷/面試情境下如果有人
真的去讀程式碼或戳 API，會直接戳破「這個專案有用 Redis」這個宣稱的落差，所以列為
高優先立刻補。

**完成：**

- `RestaurantRepository::search()`：包一層 `Cache::tags(['restaurants'])->remember()`，
  key 用排序過的完整 filters（含 cursor/sort/per_page）算 md5，不同頁/不同條件各自
  獨立的 cache entry，TTL 300s。
- `RestaurantRepository::findForDetail()`：新方法，`restaurant:{id}` cache，TTL 600s。
  **關鍵設計決定**：`RestaurantController::show()` 原本用 Laravel implicit route model
  binding（`Restaurant $restaurant`），這樣 Laravel 會在進 controller「之前」就先查一次
  DB 做綁定，包再多 `Cache::remember()` 都是狀後諸葛——改成收原始 `int $restaurant`，
  查詢本身（含 eager load）整個包進 cache closure，才是真的省掉 DB 查詢。
- `RestaurantObserver`／`RestaurantConfidenceScoreObserver`（`app/Observers/`）+
  共用的 `RestaurantCacheInvalidator`：`Restaurant`／`RestaurantConfidenceScore` 存檔或
  刪除時，`Cache::forget("restaurant:{id}")` + `Cache::tags(['restaurants'])->flush()`。
  兩個 model 都要掛，因為 confidence score 是獨立的表，只更新它不會觸發 Restaurant
  的 saved event，但 detail cache 裡內嵌了 confidence_score 欄位。**不做**
  `Cache::flush()`（總體規劃第十七節明講禁止），tags 機制只清跟餐廳相關的 key。
- `AppServiceProvider::boot()` 新增 `RateLimiter::for('api', ...)`：60 次／分鐘，依
  登入使用者 id 或 IP 分桶，底層走 `CACHE_STORE=redis`（已經是 Redis，不需要額外套件）。
  `routes/api.php` 整個檔案包一層 `Route::middleware('throttle:api')`——不是只套
  文件明講的 `/restaurants`，因為其他匿名可存取端點（`/diets`／`/geocode` 等）一樣有
  被打爆的風險。
- 6 個新測試（`tests/Feature/Api/RestaurantCachingTest.php`）：重複搜尋/詳情請求
  **直接斷言 `DB::getQueryLog()` 是空陣列**（不是只驗證回應內容對，回應對不代表真的
  沒打 DB）、不同篩選條件各自獨立快取、修改餐廳／修改 confidence score 都會讓 detail
  cache 正確失效、超過限流回 429。

**過程中做的自我驗證（不是寫完就交差）：**

1. 拔掉 `findForDetail()` 的 `Cache::remember()` 重跑測試——「repeated detail request
   hits cache not the database」真的紅了（`assertCount(0)` vs 實際 5 個 query）。
2. 拔掉 `AppServiceProvider::boot()` 裡兩個 `::observe()` 註冊重跑測試——兩個
   invalidation 測試都真的紅了（改了餐廳名字/confidence score，detail API 還是回舊值）。
   加回去後全綠。
3. **不只信任 phpunit 用的 array cache store**：用 `redis-cli -n 1 KEYS "*"`
   直接看真的 Redis（`REDIS_CACHE_DB=1`），確認 `restaurant:1` 這個 key 真的存在，
   而且 tags 機制產生的 hash key／`:timer` companion key 也在；用 tinker 改一筆餐廳
   名稱後重打 API，確認真的從 DB 撈到新名稱，不是繼續吐舊的 Redis 快取內容；
   `curl -sI` 看到 `X-RateLimit-Limit: 60`／`X-RateLimit-Remaining` header 真的存在。
   這三步都是對著這台機器上真正在跑的 Redis／MySQL container 做的，不是只看
   phpunit 綠燈就結案。

**實測：**

74 個測試（含新增 6 個）、229 個 assertion 全綠，Pint／PHPStan 乾淨。`docs/api.md` 新增
Caching／Rate Limiting 兩個段落，`README.md` 的 Caching Strategy／Security 段落改成
反映真實現況（原本這兩段其實是 Phase 11 寫 README 時就內容超前於實作的「應該有」，
不是真的有——現在補齊，文件才跟程式碼一致）。

**未完成 / 等待確認：**

- Cache 命中率沒有另外記錄追蹤（見 [docs/observability.md](observability.md)），
  只驗證過「有沒有打 DB」，沒有量測 production 流量下的實際命中率。
- Rate limit 目前是全域 60/分鐘一組規則，沒有依端點類型（例如寫入類 vs 唯讀類）
  細分不同限流值——這個專案的規模，細分限流的 ROI 目前偏低，先用同一組簡單規則。

## 2026-08-24 — 補：安裝 Laravel Telescope（本機除錯工具）

上一個 session 中斷前已裝到一半（`composer.json` 加了 `laravel/telescope`、
`TelescopeServiceProvider`／`config/telescope.php`／migration 都已產生並跑過 migrate），
但沒有 commit、也沒有寫進 `docs/todo.md`／`docs/progress.md`。接手後先驗證現況再決定
要繼續完成還是回退：

- `AppServiceProvider::register()` 只在 `local`／`testing` 環境才 `$this->app->register(TelescopeServiceProvider::class)`，
  不進 `bootstrap/providers.php` 固定清單——production 不會多一層中介層開銷，`/telescope`
  路由在 production 也不存在，比只靠 `TelescopeServiceProvider::gate()` 白名單多一層防護。
  `laravel/telescope` 掛在 `require`（不是 `require-dev`），這是 Laravel 官方文件明講的
  「只在特定環境註冊」模式所需的寫法——包管理層面它仍要能在任何環境被 autoload 到，
  只是 provider 註冊本身被環境擋掉。
- gate() 白名單目前是空陣列（`in_array($user->email, [])`），代表 production 環境下沒有
  任何人能看 `/telescope`（即使繞過上面那層 provider 限制），這是刻意的保守預設，不是
  漏寫。

**自我驗證：**

1. `php artisan migrate:status` 確認 `create_telescope_entries_table` 已經在 batch 2 跑過。
2. `php artisan test`：74 個測試、229 個 assertion 全綠，跟裝之前的數字一致——確認
   Telescope 沒有干擾既有功能。
3. Pint `--test`／PHPStan level 5 都乾淨。
4. 瀏覽器真的打開 `http://localhost:8080/telescope`，Requests 分頁真的顯示出剛才
   `php artisan test` 過程中打過的 `/api/v1/diets`／`/api/v1/restaurants`／
   `/api/v1/restaurants/1` 三筆真實請求記錄，不是空頁面或 500。

`README.md` Observability 段落補一小段說明用途與環境限制範圍。

## 2026-08-24 — 補：`users:promote {email}` Artisan 指令（技術債清單第一項）

`docs/todo.md`「已知技術債」列的第一項——之前升 admin 只能手動改 DB，補一支正式指令。

- `app/Console/Commands/PromoteUser.php`：`users:promote {email}`，查無此 email 回
  `FAILURE` 並印錯誤訊息；已經是 admin 則印訊息後直接回 `SUCCESS`（不是重複 update）；
  否則把 `role` 改成 `admin`。Laravel 11 對 `app/Console/Commands` 自動探索註冊，不用
  手動加進 `routes/console.php`。
- 3 個新測試（`tests/Feature/Console/PromoteUserTest.php`）：成功升級、已是 admin 的
  no-op、查無使用者的失敗情境，都斷言 exit code。

**過程中觀察到、但確認跟這次改動無關的既有問題（環境層級，非程式碼 bug）：** 在同一台
機器上短時間內連續重跑 `php artisan test` 好幾次，有一部分次數出現
「Table 'veggiemap_testing.reviews'／'restaurants' doesn't exist」的 `QueryException`，
影響到跟這次改動完全無關的 `ReviewServiceConcurrencyTest`／`RuleBasedRecommendationServiceTest`。
一開始懷疑是 `ReviewServiceConcurrencyTest` 背景 subprocess 資源爭用，但排查發現：
`ps aux` 確認**這台機器同時有多個 Claude Code session 在跑**（不只這個對話），全部指向
同一個 `docker-compose` stack、同一個 `veggiemap_testing` 資料庫；過程中還看到另一個
session 正在改 `app/Providers/AppServiceProvider.php`（把 Telescope 排除出 `testing`
環境，懷疑跟這個現象有關）——套用那個修改後異常依然存在，排除 Telescope 是根因。
真正吻合的解釋是**多個 session 各自的 `php artisan test` 同時打同一個 MySQL 測試庫**：
`RefreshDatabase` 把每個測試包在交易裡 rollback，如果另一個 session 的測試流程在
同一時間對同一張表做 DDL／大量 DML，就會讓彼此的連線互相看到不一致的 schema 狀態。
單一 session、沒有其他人同時在跑測試時，連續驗證 4 次都乾淨；Phase 12 記錄的 GitHub
Actions（每次 run 都是獨立 MySQL service container，不共用）也一直是綠燈。**結論**：
不是這次 Telescope／PromoteUser 改動的問題，也不是程式碼本身的併發 bug，是本機開發
情境下「多個 session 共用同一個 docker-compose 測試資料庫」的已知風險——之後如果要
同時開多個 session 對這個 repo 跑測試，要嘛錯開時間，要嘛之後才值得投資讓每個 session
用獨立的測試庫（目前判斷 ROI 不到需要現在做的程度，先記錄下來）。

## 2026-08-24 — 補：`routes/console.php` 排程（技術債清單第三項）

`docs/todo.md`「已知技術債」列的第三項——`restaurants:sync`／`restaurants:recalculate-ratings`／
`restaurants:calculate-scores` 原本都只能手動執行。

- `restaurants:recalculate-ratings`／`restaurants:calculate-scores`：兩支都沒有必填參數，
  直接 `Schedule::command(...)->daily()`，`php artisan schedule:list` 實測確認正確註冊、
  `Next Due` 時間正常。
- `restaurants:sync` 比較麻煩：`--bbox` 是必填參數（Phase 8 就這樣設計，避免一次撈全台灣），
  但這個專案**從來沒有正式決定過要自動涵蓋哪些城市範圍**——查證 `docs/architecture.md`／
  `docs/external-apis.md`／`config/services.php` 都沒有任何「預設涵蓋範圍」的紀錄，只有
  Phase 8 測試用的 5 筆 mock fixture（台北/台中/高雄各一到三家）。如果我自己編一組
  台灣城市座標當預設值排程，等於是在假裝這是一個做過的產品決策——比照 Phase 3 對
  `open_now` 篩選參數的處理方式（查無對應設計時誠實回報、不擅自二選一），這裡改用
  `EXTERNAL_API_SYNC_BBOXES` 環境變數（`config('services.sync_bboxes')`）控制：格式
  `"minLat,minLng,maxLat,maxLng"`，多組用分號分隔，**留空就完全不排程**，不是空陣列
  當一個隱性的「反正不會執行」佔位符。
- `config/services.php` 新增 `sync_bboxes`（解析 env）、`.env.example` 補上
  `EXTERNAL_API_SYNC_BBOXES=`（含說明為什麼預設空白）。
- 3 個新測試（`tests/Feature/Console/ScheduleTest.php`）：確認兩支無參數指令有進排程、
  確認 `sync_bboxes` 預設空陣列時 `restaurants:sync` 真的沒有被排進去（用
  `app(Schedule::class)->events()` 直接檢查排程事件清單，不是只看程式碼字面）。

**實測（不只信任測試綠燈）：**

1. `php artisan schedule:list`：確認只有 `restaurants:recalculate-ratings`／
   `restaurants:calculate-scores` 出現，`restaurants:sync` 沒有（預設 `EXTERNAL_API_SYNC_BBOXES`
   空白）。
2. 用 `docker compose exec -e EXTERNAL_API_SYNC_BBOXES="25.00,121.51,25.07,121.58;22.60,120.28,22.68,120.35"`
   重跑 `schedule:list`：兩組 bbox 各自產生一條 `restaurants:sync --bbox='...'` 排程，
   確認分號分隔解析邏輯是真的在解析環境變數，不是死代碼。
3. 全部 79 個測試（含新增 5 個）、238 個 assertion 全綠，Pint／PHPStan 乾淨。

**未完成 / 等待確認：**

- `EXTERNAL_API_SYNC_BBOXES` 目前預設空白，代表**這個排程功能裝好了但預設不會實際跑**——
  如果之後要讓它真的自動匯入資料，需要使用者/產品先決定要涵蓋哪些城市或商圈的 bbox，
  這不是我該替專案決定的範圍。

## 2026-08-24 — 補：安裝 Laravel Horizon（技術債清單第二項，也是最後一項）

`docs/todo.md`「已知技術債」列的第二項——Phase 6 當初的決定是「沒有 queue worker，
`dispatchSync()` 頂著」，現在把 queue worker 真的裝起來，把技術債清乾淨。

- `composer require laravel/horizon` + `php artisan horizon:install`：產生
  `app/Providers/HorizonServiceProvider.php`（`bootstrap/providers.php` 固定註冊，跟
  Telescope 刻意排除在 production 外的做法不同——**Horizon 本來就該在 production 跑**，
  它不只是除錯工具，是真正的 queue worker 管理面板，沒有它佇列就沒人消化）、
  `config/horizon.php`（沿用預設 `supervisor-1`，`balance=auto`，local 10 processes／
  production 3 processes，這個專案規模用預設值即可，不用客製）。
  `HorizonServiceProvider::gate()` 白名單維持空陣列，跟 `TelescopeServiceProvider::gate()`
  同一套「production 預設沒有人能看」的保守慣例。
- `docker-compose.yml` 新增 `horizon` service：跟 `app` 同一個 image／volume，只是
  `command: php artisan horizon` 取代預設的 php-fpm entrypoint，`restart: unless-stopped`
  確保 worker 掛掉會自動重啟。
- 5 個呼叫點全部從 `dispatchSync()` 改回 `dispatch()`：`ReviewService::submit()`、
  `RestaurantController@hide`（Admin 隱藏評論）、`RestaurantSyncService::sync()`、
  `restaurants:recalculate-ratings`、`restaurants:calculate-scores`。Job 類別本身
  （`ShouldQueue` 早就實作好）完全不用改，這正是 Phase 6 當初的設計意圖。

**實測（真的驗證非同步生效，不是只看程式碼改對）：**

1. `docker compose up -d --build horizon`：`docker logs veggiemap-horizon` 看到
   `Horizon started successfully`。
2. Tinker 裡對一家 rating=0 的餐廳呼叫 `ReviewService::submit()` 送出 5 分評論，**立刻**
   檢查 `restaurant->rating` 仍是 0（因為現在是真的丟進 Redis 佇列，不是同步阻塞完成）、
   等 2 秒後再查詢，`rating` 變成 5.00——證明 Horizon worker 真的把佇列裡的
   `RecalculateRestaurantRatingJob` 撈出來執行了，不是死路徑也不是假的非同步（同步執行
   的話第一次查詢就會立刻是新值，不會有這個「先舊值、等一下才變新值」的時間差）。
3. `redis-cli LLEN queues:default` 在 dispatch 後短暫非零、worker 處理完後歸零，確認
   真的有進佇列又被消化，不是 Redis 佇列設定錯誤導致 job 直接被丟棄看起來「剛好」成功。
4. 全部 79 個測試、238 個 assertion 全綠，Pint／PHPStan 乾淨（測試環境
   `phpunit.xml` 的 `QUEUE_CONNECTION=sync` 讓 `dispatch()` 在測試裡還是同步執行完再
   斷言，不需要真的等 worker，這是 Laravel 測試的標準作法，不是繞過驗證）。

**過程中的環境雜訊（跟這次改動無關，記錄避免下次誤判）：** `docker compose up -d --build
horizon` 這個指令意外把 `mysql` container 一併 `Recreate`（不是只建立 `horizon`），
執行後立刻查證 `veggiemap`／`veggiemap_testing` 兩個資料庫的資料筆數都完好（volume
沒有被清掉，只是 container 重建），不是資料遺失事故。全速重跑 `php artisan test` 時
偶爾出現大量隨機失敗，是前面已經記錄過的「本機同時有多個 Claude Code session 共用同一個
`docker-compose` 測試資料庫」已知風險（見上一則 `users:promote` 的記錄），不是這次
Horizon 改動造成——單獨用 `--filter` 跑受影響的測試檔（`CalculateRestaurantScoreJobTest`／
`ReviewServiceConcurrencyTest`）跟間隔幾秒後重跑完整套件都拿到過乾淨的全綠結果。

**未完成 / 等待確認：**

- `HorizonServiceProvider::gate()` 白名單目前是空陣列，跟 Telescope 一樣預設沒有人能在
  production 看 `/horizon` 儀表板——如果真的要部署到 production 且需要有人看儀表板，
  要記得把實際的 admin email 填進去，這是刻意的保守預設，不是漏寫。
- `config/horizon.php` 的 `supervisor-1` 沒有依 Job 類型分開不同 supervisor／queue（例如
  外部 API 同步 vs 批次計算分開），這個專案目前只有 2 種 Job、共用 `default` queue 已經
  夠用，之後 Job 種類變多再重新評估要不要分。

## 2026-08-24 — 補：Database ERD（總體規劃「最終完成標準」第 2 項）

`docs/database.md` 一直只有逐表的文字說明，沒有視覺化的 ERD——總體規劃最後「完成後請提供」
清單明講要有「Database ERD」，跟「1. 專案架構圖」是分開列的兩個項目，文字表格不算數。

- `docs/database.md` 新增 `## ERD` 段落，Mermaid `erDiagram`，直接對照
  `database/migrations/` 實際欄位與外鍵手動核對過（不是憑 Phase 0 的舊設計稿），涵蓋
  13 張核心表，排除 `personal_access_tokens`／`telescope_entries` 等框架基礎設施表。
- **真的用 `@mermaid-js/mermaid-cli` 渲染驗證過**，不是寫完語法看起來對就結案——
  第一次跑就抓到 2 個真的語法錯誤：屬性註解字串裡帶逗號會讓 ER 屬性解析器誤判
  （`"0-100, CalculateRestaurantScoreJob 更新"` 這種寫法會炸），以及
  `restaurant_confidence_scores.restaurant_id` 這種「同時是 PK 也是 FK」的複合鍵，
  Mermaid ER 語法一個屬性只能掛一個 key 關鍵字，`PK_FK`／`PK FK` 都不合法，改成
  `PK` 搭配文字註解「also FK to restaurants.id」表達。修完渲染出 PNG 用 Read 工具
  肉眼確認過 13 張表跟關聯線都正確，不是只看 CLI 沒有報錯就假設圖是對的。
- README「Database Design」順便修正一個既有的小錯誤：原本把 `personal_access_tokens`
  算進「13 張表」的清單裡，但 `docs/database.md` 的 13 張表本來就不含它（它是 Sanctum
  的框架表）——連著加 ERD 連結一起修掉，不是這次才引入的問題。

**追加（同一輪順手補掉，不是留到下次）：** 既然 ERD 已經抓到 ASCII art 不夠正式這件事，
乾脆把 `docs/architecture.md`／`README.md` 的系統圖也一併換成用同一套 `mermaid-cli`
驗證過的 Mermaid `flowchart`，回應總體規劃「最終完成標準」第 1 項「專案架構圖」。順便
對照現況重畫時發現，原本的 ASCII 圖裡的 `SyncRestaurantDataJob`／`ProcessUserReportJob`
從來沒有實作過（`restaurants:sync` 是同步 Artisan 指令不是 Job，使用者回報是同步處理
沒有對應 Job）——這張圖從 Phase 0 畫完後沒人回來對照實際程式碼更新過，新圖只畫真的
存在的元件（`CalculateRestaurantScoreJob`／`RecalculateRestaurantRatingJob`、
`RecommendationServiceInterface`）。

**未完成 / 等待確認：**

- 無。「最終完成標準」清單的 10 項（架構圖、ERD、API 文件、Docker、測試結果、CI/CD、
  效能考量、安全性考量、外部 API 文件、README）目前逐項核對都有對應產出。

## 2026-08-25 — `EXTERNAL_API_SYNC_BBOXES` 預設值：台北市

2026-08-24 那則排程記錄的「未完成 / 等待確認」唯一一項——排程裝好了但預設空白，等產品端
決定涵蓋範圍。使用者 2026-08-25 決定：用台北市當預設。

- `.env.example`／`.env` 都填 `24.9613,121.4570,25.2130,121.6663`（台北市行政區範圍，
  非新北）。CI 是 `cp .env.example .env`，所以 CI 環境也吃得到這個預設值。
- **這次改動打破了一個既有測試的前提**：`ScheduleTest::test_sync_bboxes_config_is_empty_by_default_so_sync_is_not_scheduled`
  斷言 `config('services.sync_bboxes')` 是空陣列，改完立刻紅。這裡沒有把斷言放寬帶過——
  該測試真正要守的是「空設定就不排程」這個條件分支，而不是「環境變數剛好是空的」。
  改寫成用 `$this->app->instance(Schedule::class, ...)` + `Facade::clearResolvedInstance()`
  注入乾淨的 Schedule，再 `require base_path('routes/console.php')` 重跑一次排程註冊，
  這樣可以對任意 config 值測試，完全不依賴 `.env`。拆成三條：空設定不排程、兩組 bbox
  各產生一條排程（含 bbox 字串真的有帶進指令）、預設 env 是台北市。
- **反向驗證**：把 `routes/console.php` 的 `foreach (config('services.sync_bboxes') ...)`
  暫時換成 `foreach ([] ...)`，「每組 bbox 各產生一條排程」那條真的紅了才還原——確認
  新測試不是永遠 PASS 的裝飾品。
- `php artisan schedule:list` 實測：`restaurants:sync --bbox='24.9613,121.4570,25.2130,121.6663'`
  真的出現在排程清單裡，`Next Due` 正常（01:00，錯開在 rating／score 重算之後）。
- 全套 81 個測試、241 個 assertion 全綠，Pint／PHPStan level 5 乾淨。

**順手修掉的既有問題（不是這次引入的）：** `routes/console.php` 有兩段註解已經跟程式碼
現況脫節——一段說「沒有 queue worker，底層 Job 用 dispatchSync」（Horizon 那輪早就改回
`dispatch()` 了），一段說「這個專案沒有正式決定過要自動涵蓋哪些城市範圍……不要自己編一組
座標假裝是產品決策」（現在已經有正式決定了）。留著會誤導下一個接手的人，一併更正。

**未完成 / 等待確認：**

- 排程現在真的會每天 01:00 打 Overpass 匯入台北市範圍的餐廳資料。目前 `.env` 的
  `EXTERNAL_API_RESTAURANT_PROVIDER=mock`，所以本機排程實際跑起來吃的是 fixture 不是
  真的 Overpass；要讓它真的匯入外部資料，需要另外把 provider 切成 overpass。這是既有的
  環境設定，不是這次改動的一部分，但值得在真的啟用排程前確認一次。

## 2026-08-25 — provider 切 osm：先修查詢範圍，再踩到 Overpass 的 406

承上一則。使用者要求把 `EXTERNAL_API_RESTAURANT_PROVIDER` 從 `mock` 切成真的 Overpass。
兩件事在動手前就擋下來了，第三件是跑下去才炸出來的。

**1）「overpass」不是合法值，會靜默 fallback。** `AppServiceProvider` 綁定判斷是
`=== 'osm'`，填 `overpass` 不會報錯，只會安靜地回 `MockRestaurantProvider`。正確值是
`osm`。（`restaurants:sync --provider=` 那條路反而是安全的，`match` 有 default throw。）

**2）查詢根本沒有素食篩選——這才是大問題。** `OsmRestaurantProvider::buildQuery()` 只查
`amenity=restaurant|cafe`，`RestaurantSyncService` 也只跳過沒有名字的節點，`dietCodes`
純粹是拿來寫關聯表、不是過濾條件。實際對 Overpass 量過台北市 bbox：

| 查詢 | 節點數 |
| --- | --- |
| `amenity=restaurant\|cafe`（原本會抓的） | 15,974 |
| `diet:vegetarian=yes\|only` | 385 |
| `diet:vegan=yes\|only` | 142 |
| `diet:vegetarian=only ∪ diet:vegan=only`（現在抓的） | 222 |

也就是排程每天會把整個台北市 16k 家餐廳灌進一個素食地圖。使用者決定只收純素食店
（`only`，不含「有素食選項」的 `yes`），查詢改成 `(...)` union 兩條 statement——Overpass QL
的多個 `[tag]` 是 AND，要 OR 只能靠 union。

`OsmRestaurantProvider` 原本是**零測試覆蓋**，查詢字串從沒被斷言過，這就是為什麼缺篩選
一直沒人發現。新增 `tests/Feature/External/OsmRestaurantProviderTest.php`，其中一條用
regex 掃出每一條 `node[...]` statement、逐條要求帶 `"only"`，未來加第三個 diet 標籤時
漏篩一樣會紅。

**3）切過去第一次跑，created 0——Overpass 用 HTTP 406 擋掉 Guzzle 預設 User-Agent。**
`--bbox=25.00,121.51,25.07,121.58` 預期 106 筆，實際 0 筆卻回傳 SUCCESS。查
`external_api_logs` 只看到 `status=0 / error_code=RequestException`，資訊量為零。分層排查：
容器內 `curl` 打同一個 query 拿到 **200**，排除網路與查詢語法；改用 Laravel 的 `Http` 打
同一個 URL 拿到 **406**；只差一個 `User-Agent` header，加上去就 200。根因一句話：**沒帶
User-Agent → overpass-api.de 回 406 → `retry()` 包成 RequestException 丟出 → catch 吞掉回
空陣列 → 同步印出「created 0」並回報成功。** 這跟 Phase 8.5 那次 Nominatim 因 UA 帶
`example.com` 被 403 是同一族的坑，只是這次是完全沒帶。

修法：
- `config/services.php` 新增 `overpass.user_agent`，`.env` 留空時沿用 Nominatim 那組。
  這裡踩到第二層：`env('X', env('Y'))` 對「已定義的空字串」不會套用預設值，
  `EXTERNAL_API_OVERPASS_USER_AGENT=` 會讓 UA 變成空字串再被 406 擋掉——改用 `?:`，
  且**發布前真的 `config:clear` 印出來確認拿到字串**，不是改完就假設生效。
- provider 加 `->withHeaders(['User-Agent' => ...])`，並新增一條測試斷言這個 header。
- 加 `catch (RequestException $e)` 從例外裡取回真實狀態碼，log 記 `HTTP_406` 而不是
  `RequestException`。原本 `if (! $success)` 裡那段 `HTTP_{status}` 是**死碼**——`retry()`
  預設 throw，非 429 的失敗根本走不到那行。這是既有問題，不是這次引入的。

**實測結果（修完重跑同一個 bbox）：** created **106**，跟事前用 `out count;` 查到的預期值
完全一致；DB 從 20 筆變 126 筆，`source_id` 非空的正好 106 筆；抽查是真的台北素食店
（春天素食／世界素食館／十方齋素食館／古佛素食），座標與 diet 關聯都正確；
**`whereDoesntHave('dietTypes')` 為 0**，確認沒有非素食店混進來；log 記到
`status=200 success=1`。87 個測試、263 個 assertion 全綠，Pint／PHPStan 乾淨。

反向驗證做了兩輪。第一輪**失敗但沒發現**：取代字串沒對到原始碼的跳脫形式，檔案根本沒被
改到，測試自然全綠——差點把「沒改到所以綠」誤讀成「拔掉防線也綠」。用 `diff` 對照備份才
抓到。重做後：拔掉 diet 篩選 → 2 條紅；拔掉 UA header → 1 條紅。

**未完成 / 等待確認：**

- `.env.example` 仍是 `EXTERNAL_API_RESTAURANT_PROVIDER=mock`（使用者選的 A 案）：只有本機
  切 osm，CI 與新 clone 的開發者維持不打外部 API 的安全預設。
- **全台北市 bbox 尚未實跑**，目前只驗證過市中心那組小 bbox（106 筆）。全市預期 222 筆，
  小 bbox 那次 fetch 花了 17.3 秒（timeout 設 30 秒），全市會更久，值得在啟用排程前實測一次
  確認不會 timeout。
- 純素食店的 diet 關聯依賴 OSM 標籤本身：例如「養生素食」只有 `diet:vegan=only`、沒有
  `diet:vegetarian`，所以在我們的 DB 裡只會掛到 vegan 而不是兩個都掛。要不要自動推導
  「vegan=only 蘊含 vegetarian」是產品決定，沒有擅自加。

## 2026-08-25 — 預設涵蓋範圍改台中市，並把東京 23 区規劃進去

使用者決定：台中市當預設素食地圖，東京也一併規劃。

**台中市 bbox 校正。** 先用 `out count;` 量過再定範圍，不是憑印象填座標。第一版
`24.0000,120.4400,24.4500,121.4600` 量到 166 筆；懷疑南緣切到霧峰、西緣切到龍井海線，
放寬成 `23.9500,120.4300,24.4500,121.4700` 後變 **177 筆**——確實漏了 11 家，採放寬版。

**實測結果。** `restaurants:sync --bbox=23.9500,120.4300,24.4500,121.4700`（**沒帶
`--provider`**，走 `.env` 的 `EXTERNAL_API_RESTAURANT_PROVIDER=osm`，順帶驗證 config 這條
路徑也通）：created **177**，耗時 13.5 秒，與事前 count 查詢完全一致。DB 從 126 筆變 303 筆，
`whereDoesntHave('dietTypes')` 為 0。抽查是真的台中素食店（養生素食／愛家素食／天華素食／
林素食／素食麵／觀音齋），座標落在台中無誤。diet 分布：vegetarian 163、vegan 42。

抽樣時自己踩了一個小陷阱值得記：第一次列印樣本忘了加 `whereNotNull('source_id')`，
列出來的是 Faker 種子資料（「Lakin, Hartmann and Roob 蔬食」這種公司名），差點誤讀成
「匯入資料長得很怪」。加上過濾才是真的 OSM 資料。

**東京 23 区：標籤密度跟台灣完全不同，這是產品層級的問題。**

| 範圍 | `only`（純素食店） | `yes\|only`（含「有素食選項」） | only 佔比 |
| --- | --- | --- | --- |
| 台中市 | 177 | 220 | 80% |
| 東京 23 区 | 46 | 210 | 22% |

日本 OSM 社群慣用 `diet:vegan=yes` 標「有純素選項」，很少標 `only`。沿用我們 2026-08-25
「只收純素食店」的規則套到東京，整個東京 23 区只會有 **46 家**——不是東京素食店少，是
標籤慣例不同。目前**先維持一致規則（`only`）把東京排進 `EXTERNAL_API_SYNC_BBOXES`，
但沒有實際匯入**，因為「要不要為日本放寬成 `yes`」是產品決定，不該我自己選。

**本機沒有 scheduler。** `docker-compose.yml` 沒有 `schedule:work` 的 service，執行中的
container 只有 app／horizon／mysql／nginx／redis。所以 `php artisan schedule:list` 顯示的
兩條排程**實際上不會自動執行**——東京不會在 01:00 自己匯入。Horizon 是 queue worker，
不是 scheduler，兩者不能互相代替。這是既有缺口（`docs/deployment.md` 有寫 production 的
排程建議，但本機開發環境沒有對應設定），不是這次引入的。

`ScheduleTest` 那條 `test_default_env_schedules_taipei_bbox` 因為預設值改變而紅了，改成
`test_default_env_covers_taichung_and_tokyo` 並新增一條驗證分號分隔真的產生兩條獨立排程
（不是合併成一次大查詢）。88 個測試、264 個 assertion 全綠，Pint／PHPStan 乾淨。

**未完成 / 等待確認：**

- **東京尚未實際匯入**，等「`only` 還是 `yes`」的決定。若維持 `only` 是 46 家，
  放寬成 `yes` 是 210 家但會混入「有素食選項的一般餐廳」，與台灣目前的收錄標準不一致。
  另一種折衷是依國別套不同規則，但那會讓「這個地圖收什麼」變成兩套標準，要想清楚。
- 台北市那 106 筆是上一輪測試匯入的，仍留在 DB 裡（預設涵蓋範圍已不含台北）。要不要清掉
  或保留成多城市資料，沒有擅自決定。
- 匯入資料裡 `city` 欄位有 77/177 是空字串而不是 NULL（OSM 沒有 `addr:city` 標籤時），
  `address`／`district` 同樣沒有 NULL。語意上 NULL 比空字串正確，但這是既有的 upsert 行為，
  不在這次範圍內，先記錄。

## 2026-08-25 — 收錄規則依國別而異：台中 only、東京 yes

承上一則的待決項。使用者決定：依國別套不同規則，日本用 `yes`。

**資料模型從「一串 bbox」升級成「範圍＋規則」。** 規則必須跟著範圍走，否則東京會被套上
台灣的門檻。`EXTERNAL_API_SYNC_BBOXES` 每組格式改成 `bbox@規則`，省略時預設 `only`
（向後相容）；`config('services.sync_bboxes')` 改名為 `sync_regions`，回傳
`[['bbox' => ..., 'diet' => ...], ...]`。`routes/console.php` 把 `--diet` 一起排進指令。

- `OsmRestaurantProvider` 建構子收 `only`／`yes`，未知值丟 `InvalidArgumentException`。
  `yes` 模式的查詢是 `~"^(yes|only)$"` 而**不是** `="yes"`——純素食店本來就該落在「有素食
  選項」這個較寬的集合裡，只比對 `yes` 反而會把純素食店漏掉。
- `restaurants:sync` 新增 `--diet=` 選項。順帶修掉 `resolveProvider()` 的靜默退回：原本
  `--provider` 沒帶時是 `app(RestaurantProviderInterface::class)`，而該綁定是
  `=== 'osm' ? Osm : Mock`，把 `EXTERNAL_API_RESTAURANT_PROVIDER` 打成 `overpass` 之類的值
  會安靜跑 mock。現在指令層對未知值直接 throw。（`AppServiceProvider` 的綁定本身仍是靜默
  退回，沒有一併改動——那影響全 app 的解析行為，不在這次範圍。）

**踩到的坑：`$signature` 裡的選項描述不能換行。** 原本為了排版把 `--diet` 的說明折成兩行，
Laravel 的 signature parser 直接吃不到這個選項，執行時報
`The "--diet" option does not exist.`——不是選項沒定義，是定義被解析器丟掉了。改回單行即可。

**東京實測。** `--bbox=35.53,139.56,35.82,139.92 --diet=yes`：created **195**、17.3 秒。
事前 count 查詢是 210，差 15 筆——**沒有當成誤差放過**，改查「有 `name` 標籤的節點數」
得到 **195**，與匯入數完全吻合，差的 15 筆是 OSM 上沒有名字的節點，被 `parseElements`
正確跳過（`skipped` 統計是 0，因為它們在進到 `RestaurantSyncService` 之前就被濾掉了）。
DB 從 303 筆變 498 筆，東京 bbox 內 195 筆，`whereDoesntHave('dietTypes')` 為 0。
diet 分布：vegetarian 123、vegan 121。

**`yes` 的後果要誠實記下來**：東京匯入的 195 家裡有 CoCo壱番屋、AFURI、
ドトールコーヒーショップ 這類連鎖店，它們是「有純素選項的一般餐廳」而不是素食餐廳。
這是選 `yes` 的必然結果，使用者知情下的決定，不是資料錯誤。台中那 177 家維持 `only`，
不受影響。

反向驗證：把 `routes/console.php` 的 `'--diet' => $region['diet']` 拔掉，
「每個範圍各自帶自己的規則」那條真的紅了才還原。89 個測試、268 個 assertion 全綠。

**環境雜訊（非程式碼問題）：** 本機跑 `phpstan analyse` 一度以
`reached configured PHP memory limit: 128M` 崩潰。查 `.github/workflows/ci.yml` 第 74 行，
CI 本來就帶 `--memory-limit=512M`，所以是本機預設值不足，不是新程式碼引入的問題；
本機補上同樣參數後 `[OK] No errors`。

**未完成 / 等待確認：**

- 台北那 106 筆（上上輪測試匯入、`only` 規則）仍留在 DB，預設涵蓋範圍已不含台北。
  現在 DB 是台北 106＋台中 177＋東京 195＋種子 20 = 498 筆的混合狀態。
- `AppServiceProvider` 的 `RestaurantProviderInterface` 綁定仍會對未知 provider 值靜默
  退回 mock，只有 `restaurants:sync` 這條路徑修成會 throw。
- 前端目前沒有任何「依城市／國別」切換或篩選的概念，三個城市的資料是混在同一個
  `/api/v1/restaurants` 結果裡靠座標區分。要做多城市體驗需要另外規劃。

## 2026-08-25 — 加入高雄、台南，台北留下當多城市資料

使用者決定：台北那 106 筆留著當多城市資料，並加入高雄、台南。

**台北一併排回排程。** 資料留著卻不在 `sync_regions` 裡，只會慢慢過時成為沒人更新的
陳資料，所以把台北也排進每日同步（台灣規則 `only`）。現在共五個範圍。

**bbox 又踩了同一類坑，而且這次更明顯。** 第一版高雄用
`22.4000,120.1000,23.5000,121.1000`，量到 114 筆。接著跑台南
（`22.8500,120.0000,23.4500,120.7000`）得到 **created 0、updated 45**——台南 45 筆
**全部**早就被高雄那次匯入了。原因是高雄北緣拉到 23.50，而高雄實際最北只到約 23.30
（那瑪夏），整個台南被吞進去。校正成 `22.4500,120.1500,23.3000,121.0600` 後重量是
**107 筆**，先前的 114 有 7 筆其實是台南／嘉義的店。

相鄰城市用矩形本來就會重疊（校正後台南仍有約 38 筆落在高雄 bbox 內），這無法消除，
但 upsert 以 `source_id` 為鍵所以是冪等的——重跑高雄得到 **created 0、updated 107**，
`source_id` 唯一值 592 等於匯入總筆數，確認沒有產生任何重複列。

**筆數逐一對過帳，沒有把差額當誤差放過。** 高雄 `out count;` 是 115、實際匯入 114，
改查「有 `name` 標籤的節點數」得到 114，完全吻合——差的 1 筆是 OSM 上沒有名字的節點。

**duplicates_flagged 查證過是真的重複，不是誤判。** 高雄那次標了 1 筆、台南 2 筆，
查出來是「極圓素食坊」在 OSM 上有兩個不同 id 的節點（11607709502 與 11636599688），
座標只差第 6 位小數（約 0.1 公尺），確實是同一家店被重複標記。重複偵測功能正常運作。

**修正一個我先前寫錯的數字。** 前一則記錄寫「台中 only 佔比 80%」是錯的——那是拿放寬
bbox 的分子（177）配窄 bbox 的分母（220）算出來的，兩個數字來自不同範圍。同一個 bbox
重量後 `yes|only` 是 239，正確佔比是 **74%**。已更正 `docs/external-apis.md`。

更重要的是，**「台灣都標 only」這個說法本身就是過度概括**：台中 74%，但高雄約 35%、
台南約 24%，跟東京的 22% 相去不遠。台灣四市選 `only` 的真正理由是**收錄標準一致**
（都是真正的素食店），不是「台灣標註密度高」。這個差別會影響未來要不要為台南放寬的判斷，
所以寫清楚而不是留著一個好聽但站不住的理由。

**現況：** 612 筆 = 種子 20 ＋ OSM 592（台北 106、台中 177、高雄 107、台南 45、東京 195，
各 bbox 內筆數相加 630 大於 592，差的 38 就是高雄／台南重疊區）。`whereDoesntHave
('dietTypes')` 為 0，`source_id` 無重複。90 個測試、270 個 assertion 全綠，Pint／
PHPStan 乾淨。

新增一條測試 `test_only_tokyo_uses_the_relaxed_diet_rule`：斷言五個範圍裡恰好只有一個用
`yes`、四個用 `only`。要是哪天有人順手把台灣某市改成 `yes`，那個城市會混進「有素食選項的
一般餐廳」，跟其他三市標準不一致——這條會擋下來。

**未完成 / 等待確認：**

- 前端仍沒有依城市／國別切換或篩選的概念，五個城市的資料混在同一個
  `/api/v1/restaurants` 結果裡靠座標區分。多城市體驗要另外規劃。
- 本機仍沒有 scheduler container，五條排程實際不會自動執行。
- 台南若覺得 45 家太少，可考慮改用 `yes`（187 家），但那會讓它跟其他三個台灣城市的收錄
  標準不一致，是產品決定。

## 2026-08-25 — 前端多城市切換 ＋ UI/UX 優化

資料已有五個城市，但前端沒有任何切換入口，全部混在同一組查詢結果裡靠座標區分。

**先查證一件事才決定設計方向：不能用 `city` 欄位篩選。** `RestaurantSearchParams` 早就
有 `city` 參數（只是 UI 沒接），看起來是最直覺的做法，但實際查資料庫發現不可用：

| city 欄位 | 筆數 |
| --- | --- |
| （空字串） | 351 / 592（59%） |
| 臺中市 / 台中市 | 57 / 24（**同一城市兩種寫法**） |
| 彰化縣 | 17（矩形 bbox 溢出鄰縣） |
| 渋谷区 / 千代田区 / 港区 | 東京填的是「区」不是都名 |

所以多城市切換一律走 **bbox 座標**，完全不碰 `city` 欄位。

**架構。** 新增 `config/cities.php`（slug／label／country／center／zoom／bbox）與
`GET /api/v1/cities`。城市清單是產品內容（顯示名稱、開場視角），放程式碼；
`sync_regions` 是 env 驅動的營運設定（涵蓋範圍可依環境調整），兩者分開但**用測試綁住**：
`CitiesTest` 斷言每個城市 bbox 都在 `sync_regions` 裡、每個 sync region 都有對應城市入口、
每個 center 落在自己的 bbox 內。少了這道防線，兩邊各自改就會出現「切過去是空地圖」或
「每天匯入卻沒有入口看得到」。center 刻意用市中心而非 bbox 幾何中心——台中的幾何中心
在和平區山裡、高雄的在那瑪夏，開場會是一片山林。

**網址是單一真相來源。** `?city=taichung`，切換器只負責改網址，飛過去由 watch 處理。
上一頁／重新整理／貼連結給別人三件事因此自動都對。實測按上一頁真的從東京回到台南。

### 過程中抓到的三個真 bug

**1）Leaflet `flyTo` 長距離會把磁磚圖層弄壞。** 台北切台南後畫面一片空白，但 marker
還在正確位置。逐層查證：磁磚 12 張全部 `naturalWidth=256` 載入成功、URL 算出來也確實是
台南（13/6831/3557），但量 `getBoundingClientRect()` 發現第一張磁磚在容器外 13,249px
（`tileOffscreen: true`）。先懷疑容器尺寸過期，resize 觸發 invalidateSize **沒有修好**，
排除這條。改用直接開 `?city=tainan`（`setView` 初始化）→ 完全正常，確定是 `flyTo` 動畫
本身。根因：跨約 200km 的飛行動畫留下壞掉的 tile layer transform，marker 走另一條 pane
路徑所以不受影響。修法：新增 `jumpTo()` 給城市切換用，`flyTo()` 加距離防護
（>50km 自動改用 `setView`），避免使用者在台北搜尋「東京」時踩同一個坑。

**2）計數把被截斷的數字當成總數。** 我新加的「這個範圍有 N 家」在台北顯示「100 家」，
但後端是 cursor 分頁、`per_page=100` 是上限、台北實際有 106 家。查 API 回應確認有
`meta.next_cursor`，改成 `next_cursor` 非 null 時顯示「100+ 家」。UI 文案也不該說謊。

**3）競態：三個觸發來源會互相蓋掉。** 「地圖移動完」「改篩選」「換城市」都會發查詢，
慢的舊請求可能在新請求之後回來把畫面蓋回舊資料。加上請求序號，過期回應直接丟棄。

### UI/UX 調整

- **FilterDrawer 變成真的抽屜**：手機實測 375×812 下 hero 佔掉約 570px，地圖被擠到摺線
  以下只剩約 240px。改成窄螢幕預設收合、桌機展開，地圖上移約 145px。
- **補上「清除」**：原本要取消篩選得記得剛才點了哪個晶片再點一次。順帶修掉一個既有
  bug——`filters.diet = undefined` 會留下 key，導致「還有幾個篩選條件」算錯，改成 `delete`。
- **補上空狀態**：原本查無結果就是一片空白地圖，沒有任何說明。
- **補上錯誤狀態**：原本查詢失敗會靜默留著舊資料。
- a11y：`aria-pressed`／`aria-expanded`／`role="status"`／`:focus-visible`／
  `prefers-reduced-motion`。

### 一個我自己差點誤判的環境陷阱

FilterDrawer 原本在 `onMounted` 讀一次 `matchMedia('(min-width: 768px)')`。實測時發現
桌機寬度 1280 卻仍收合，查下去是**內嵌瀏覽器分頁隱藏時 `window.innerWidth` 是 0**、
matchMedia 一律回 false，一次性判斷會誤判且永遠不會修正。改成持續監聽 `change` 事件。

但**這個修法我沒能在這個環境驗證**：裝探針測試發現，用工具改變視窗尺寸時，這個瀏覽器
分頁連原生 `resize` 事件都不會派送（探針計數 `mq:0, resize:0`，儘管 innerWidth 確實從
1100 變成 600）。所以「螢幕尺寸中途改變會自我補正」這條**只有推理、沒有實測**。
已驗證的是：600px 載入 → 收合、1100px 載入 → 展開、手動點按鈕可覆蓋。

### 驗證

- 瀏覽器實測：五個城市都切過（台北／台中／台南／高雄／東京），磁磚與 marker 正常、
  badge 數字隨範圍變動、上一頁回到前一個城市、`?city=` 直接開也正確。
- 篩選實測：點「全素（Vegan）」→ 徽章顯示 1、出現清除鈕、結果 100+ → 23 家；
  點清除 → 徽章消失、結果回到 100+。
- 後端 95 個測試、298 個 assertion 全綠；前端 Vitest 3 個測試、ESLint／vue-tsc／build
  乾淨；Pint／PHPStan 乾淨。

**未完成 / 等待確認：**

- 城市切換的**點擊層級互動沒有在這個環境驗證成功**——內嵌瀏覽器的座標映射與行動模擬
  不可靠（行動模式下點擊變成選字），最後是用 DOM `.click()` 觸發真正的 Vue handler 驗的。
  邏輯與畫面更新都確認了，但「真人用滑鼠／手指點」這層沒驗到。
- 前端仍沒有元件測試／Playwright E2E，城市切換這種跨元件行為目前只能靠手動驗證。
- `RestaurantListView`（列表頁）沒有城市概念，只有地圖首頁支援切換。
- 地圖首次載入時磁磚會有一兩秒只渲染中央十字區域，之後才補齊。看起來像容器尺寸稍晚才
  確定，但因為會自己修正、且這個環境無法可靠重現，沒有動它。

## 2026-08-25 — 補上前端元件測試（3 → 32 個）

上一則點名的缺口：城市切換這種跨元件行為只能手動驗證，而我剛好證明了手動驗證在內嵌
瀏覽器會卡在輸入層（座標映射不可靠、行動模擬下點擊變成選字）。補元件測試。

裝 `@vue/test-utils` + `jsdom`，`vitest.config.ts` 加 `environment: 'jsdom'` 與
`resources/js/test/setup.ts`（jsdom 沒有 `matchMedia`，FilterDrawer 掛載就會炸，
stub 一個並提供 `setViewportMatches()` 讓測試切換寬窄螢幕）。

**選 Playwright 還是元件測試？** Phase 10 判斷 Playwright 的 ROI 偏低，這次維持該判斷：
真正會壞、而且手動驗不到的是「元件邏輯」（哪個城市 active、undefined key、距離門檻），
不是「瀏覽器整合」。元件測試跑 1.4 秒、CI 已經在跑 `npm run test`，不用新增 CI 基礎設施。

四個測試檔、32 個測試：

- **`CitySwitcher.test.ts`（5）**：依國家分組、只有一個 active、**點擊送出 slug 而不是
  label**（送 label 網址會變 `?city=東京`，重新整理就找不到）、未知 modelValue 不會炸、
  用 `aria-pressed` 表達狀態而不是只有顏色。
- **`FilterDrawer.test.ts`（8）**：窄螢幕收合／寬螢幕展開／手動選擇蓋過預設、**取消篩選
  要 `delete` key 而不是留 undefined**（真的踩過的 bug）、徽章數字、清除鈕出現條件、
  清除一次清光、飲食類型是單選。
- **`RestaurantMap.test.ts`（5）**：mock 掉 leaflet，斷言**短距離用 `flyTo`、長距離改用
  `setView`**——這就是造成台北切台南整張地圖空白的那個 bug 的防線；`jumpTo` 一律直接跳、
  且帶的是各城市自己的 zoom（東京 12、台灣 13，寫死會讓一邊開場看錯範圍）。
- **`HomeView.test.ts`（11）**：mock leaflet ＋ api client ＋ memory history router，測
  網址優先、localStorage 備援、都沒有時用第一個、**未知 slug 退回第一個而不是留白**、
  點擊只改網址、切換時用該城市 center/zoom 直接跳、記住最後選的城市、首次載入不多飛一次；
  以及計數的 `100+`／實際筆數／空狀態三種分支。

**反向驗證都做了**：拔掉 `flyTo` 的距離防護 → 2 條紅；拔掉 HomeView 的未知 slug fallback
→ 1 條紅。確認不是永遠 PASS 的裝飾品。

### 過程中的兩個修正

**1）一個測試失敗，但查證後是測試寫法問題不是元件 bug。** 「清除會一次移除所有條件」
一開始紅，但同一個測試裡「徽章消失」「無 active 晶片」都通過了——代表元件內部狀態確實
清空了。原因是 `defineModel` 的整組替換靠 emit 回傳父層，而我的測試沒接 v-model；
反觀就地改欄位那條路徑因為改到同一個物件，沒接線也看得到。修測試（真的接上
`onUpdate:filters` 回寫），不是改元件。

**2）假資料太簡陋反而測不到重點。** 用 `{ id: i }` 當餐廳讓 `renderMarkers` 炸在
`rating.toFixed(1)`。API 一定會回 `rating`，所以是測試資料不真實；補成完整形狀的
`fakeRestaurant()`。

**驗證**：前端 32 個測試全綠（原本 3 個）、ESLint／vue-tsc／build 乾淨；後端 95 個測試
仍全綠。CI 的 Frontend job 本來就有 `npm run test` 這步，不用改 workflow。

**未完成 / 等待確認：**

- `RestaurantListView`／`SearchBox`／`AdminView` 仍無元件測試，這次只覆蓋多城市相關路徑。
- 仍然沒有跨頁面的 E2E（真瀏覽器點擊、真後端）。維持 Phase 10 的判斷：這個規模先不投入。
- `RestaurantListView` 依舊沒有城市概念，只有地圖首頁支援切換。

## 2026-08-25 — RestaurantListView 城市切換（含新增 `bbox` API 參數）

首頁有城市切換、列表頁沒有。補上時撞到一個設計問題，值得記。

**先查證再設計：既有的兩種收窄方式都不能用。**

1. `city` 欄位——已知不可靠（59% 空、「臺中市／台中市」兩種寫法、東京填「渋谷区」）。
2. `latitude`+`radius`——**這個是這次才發現的**：`SearchRestaurantRequest` 的 `radius`
   有 `max:50`，而各城市 bbox 的半對角線是台北 17.5km、台中 **59.6km**、高雄 **66.4km**、
   台南 48.9km、東京 22.9km。台中與高雄直接超標，實際 curl 也確認回 422。

所以新增 `bbox` 參數（`"minLat,minLng,maxLat,maxLng"`）。這不是硬塞的新概念——
`RestaurantRepository` 本來就用 `MBRContains` 加 bounding box 當第一段查詢，只是那個矩形
是從「中心點＋半徑」推出來的；bbox 只是讓呼叫端直接給定矩形。抽出
`polygonFromCorners()` 給兩條路徑共用。

- 帶 bbox 且不帶座標時，跳過外層 `fromSub`（沒有中心點就算不出距離，也不需要那圈包裝）。
- 帶 bbox **且**帶座標時，邊界仍由矩形決定，不再套 `distance <= radius`——否則會把矩形
  四角切掉；座標只用來算距離供 `sort=distance` 使用。
- 格式錯的 bbox 一律 422，不靜默忽略：忽略會讓「查這座城市」變成「查全世界」，
  使用者只會看到莫名其妙的結果而不是錯誤。四種錯法（非座標／數量不對／角落顛倒／
  座標超範圍）都實際 curl 驗過。

**兩個測試失敗，查證後都是我的測試錯，不是程式錯：**

1. 「邊界應該包含」——直接下 SQL 驗，`MBRContains` 對邊界是**嚴格排除**的（角落回 0、
   內縮 1e-9 回 1）。我的斷言是假設不是規格。改成把真實行為釘住（測試名稱也改成
   `..._is_exclusive`），並在註解說明為何不做 epsilon 補償：那會連帶改到既有半徑搜尋的
   語意，而 OSM 座標 7 位小數、我們的 bbox 4 位，剛好壓線的機率趨近於零。
2. 「bbox + parking 篩選」回 0——因為**測試資料庫沒有 seed lookup 表**（`TestCase` 沒跑
   seeder），`Feature::where('code','parking')->value('id')` 拿到 null，attach 等於沒做。
   改用 `Feature::factory()->create(['code' => 'parking'])`。同一組 filter 在 dev DB 上
   跑得出 3 筆，這就是判斷「不是 repository 邏輯錯」的依據。

**前端：抽出 `useCities` composable。** 首頁與列表頁都要「從網址解析目前城市」，複製一份
遲早會走鐘。composable 用 `fallback` 選項區分兩頁的差異：地圖頁 `'first'`（地圖一定得看著
某個地方），列表頁 `'all'`（**維持它原本「列出全部」的行為**，城市是可選的收窄條件，
不是必選——否則就是把既有功能拿掉）。`CitySwitcher` 加 `allowAll` prop 顯示「全部」。

重構首頁時靠上一輪剛補的 11 條測試確認沒改壞，這正是那批測試的用途。

**實測抓到一個我自己引入的浪費：** 列表頁一開始在 setup 直接 `search(true)`，城市清單載完
後 `watch(activeCity)` 又查一次——瀏覽器 network 面板看到**每次進頁面送出兩個請求**
（一個沒 bbox、一個有）。改成用一個 `searchScope` computed 統一觸發：清單沒載完是 null
不查，載完變成 bbox 或 `ALL_CITIES`，之後只有換城市才會變。重測確認只剩一個請求。

**驗證：** 後端 107 個測試、342 個 assertion 全綠（新增 12 條 bbox 測試）；前端 43 個測試
（新增 11 條列表頁測試）；Pint／PHPStan／ESLint／vue-tsc／build 乾淨。瀏覽器實測台南與
東京的列表都正確限定範圍、placeholder 帶城市名、計數顯示「台南：20+ 家」。
反向驗證：拔掉 repository 的 bbox 分支 → 後端 4 條紅；拔掉列表頁查詢參數的 bbox →
前端 5 條紅。

**未完成 / 等待確認：**

- 列表頁的 `keyword` 沒有進網址（只有 `city` 有），所以搜尋結果的連結分享出去不會帶關鍵字。
- 匯入資料的 `address` 常是空字串（OSM 沒有 `addr:street`），列表卡片會多一行空白。
  這是既有的顯示問題、首頁推薦卡片也有，不是這次引入，沒有一併改。
- `SearchBox`／`AdminView`／`RestaurantDetailView` 仍無元件測試。

## 2026-08-25 — 列表頁關鍵字進網址

上一則列的未完成項：列表頁的 `keyword` 只存在 local state，搜尋結果的連結分享出去不帶
關鍵字、重新整理也會掉。

**關鍵設計：草稿與已提交的關鍵字要分開。** 輸入框綁 `keywordDraft`，只有按下搜尋／Enter
才 `router.push` 寫進網址；實際查詢一律讀 `committedKeyword`（從 `route.query` 來）。
如果直接把輸入框 v-model 到網址，使用者按上一頁會變成**逐字倒退**而不是回到上一次的
搜尋結果。反過來，`watch(committedKeyword)` 會把值回填輸入框，這樣上一頁／直接改網址時
畫面上的字跟結果不會對不起來。

- 查詢觸發沿用上一則的 `searchScope` 單一機制，把關鍵字一起併進 key
  （`JSON.stringify([bbox ?? ALL_CITIES, committedKeyword])`），所以換城市、改關鍵字、
  首次載入都只會送出一發請求。
- 按搜尋但關鍵字沒變時 `router.push` 不會觸發 navigation，watch 也就不會跑——這種情況
  直接呼叫 `search(true)`，否則使用者按了按鈕卻什麼都沒發生。
- 空白／純空格不會寫進網址（`trim()` 後為空就 `undefined`）。
- 新增「清除」按鈕：只移除 `keyword`，`city` 留著。原本要清掉關鍵字只能自己選取文字刪掉
  再按一次搜尋。

**空狀態文案改成依情境給建議。** 原本是三段 `v-template` 拼字串，容易拼出「沒有符合條件的
餐廳。試著清掉篩選條件，或」這種斷尾。改成 `emptyMessage` ＋ `emptySuggestions` 兩個
computed，只列真正適用的建議——沒下關鍵字卻叫人「換個關鍵字」只會讓人困惑。瀏覽器實測
在東京搜「素食」得到：「東京沒有符合「素食」的餐廳。換個關鍵字，或切換到其他城市。」
（沒開篩選，所以沒有提篩選那句。）

**驗證：** 前端 54 個測試（列表頁 11 → 22），後端 107 個測試不受影響。反向驗證：把提交
改回「直接用草稿查、不進網址」→ 3 條紅（寫進網址、Enter 一致、上一頁回填）。
瀏覽器實測四件事：帶 `?keyword=` 開頁會回填並套用、換城市保留關鍵字、上一頁完整還原
（網址／輸入框／選取城市／結果四者一致）、清除只移除關鍵字保留城市。

**未完成 / 等待確認：**

- `filters`（飲食類型／寵物友善／停車）仍是 local state，沒進網址。同一套做法可以照搬，
  但參數比較多、要決定網址要長什麼樣（逐個 query 參數還是壓成一個字串），沒有擅自決定。
- 匯入資料的 `address` 常是空字串，列表卡片會多一行空白（既有問題，首頁推薦卡片也有）。
- `SearchBox`／`AdminView`／`RestaurantDetailView` 仍無元件測試。

## 2026-08-25 — filters 進網址（逐個 query 參數），並抓到一個從沒運作過的篩選

使用者決定：逐個 query 參數（`?diet=vegan&parking=1`），不是壓成一個編碼字串。理由是
網址本身要看得懂——使用者回報問題時貼得出有意義的資訊，`?f=dGVzdA` 只有程式看得懂。

**先改 FilterDrawer 的更新方式。** 它原本是「就地改欄位」（`filters.value.diet = code`），
父層把 filters 接到網址後，computed 的 getter 每次回傳新物件，就地改會落在暫時物件上、
永遠傳不出去。改成一律整組替換走 `defineModel` 的 emit，父層要存 ref 還是網址都行。
這順便消掉了我先前寫測試時發現的「就地改 vs 整組替換」兩條路徑行為不一致。

`useFilterQuery` 回傳可寫的 computed，直接接 `v-model:filters`。約定：布林在網址上一律
寫 `1`，關閉就是「沒有這個參數」，不用 `0` 佔位（否則「關閉」跟「沒設定」變成兩種表示法，
網址也會愈用愈長）。寫回時先刪掉這三個 key 再重寫，避免被關掉的條件殘留。首頁與列表頁
都改用它，行為一致。

### 抓到一個「從一開始就沒運作過」的篩選

改完在瀏覽器實測 `?city=taichung&diet=vegan&parking=1`，UI 狀態全對（徽章 2、兩個晶片
選取）但查詢**失敗**。查 network 面板：

```
GET /api/v1/restaurants?...&diet=vegan&parking=true → 422
{"parking":["The parking field must be true or false."]}
```

根因：axios 把布林 `true` 序列化成字串 `"true"`，而 Laravel 的 `boolean` 規則只接受真布林
與 `1`/`0`/`"1"`/`"0"`，不吃 `"true"`。

**這是既有 bug，不是這次引入的**——`git show HEAD~3` 確認舊版 FilterDrawer 就是
`filters.value.parking = true`，再原封不動展開進 axios params。也就是「寵物友善」「停車」
兩個篩選**從實作出來就沒有真正運作過**。之所以一直沒人發現，是因為舊版前端沒有錯誤處理，
422 被靜默吞掉、畫面只是沒更新，看起來像「這個條件剛好沒有結果」。是我這輪新加的錯誤
狀態把它照出來的。

修法：新增 `apiFilterParams()` 在請求邊界把布林轉成 `1`（跟網址的表示法一致），
並補一條測試釘住「送 1 不是 true」。沒有改後端驗證規則——那會影響所有 API 使用端，
而 `1` 本來就是我們自己網址上的約定。

**修完實測**：`diet=vegan` 44 筆、`parking=1` 2 筆、兩者交集 1 筆，數字互相吻合，
不是湊巧回一筆。

### 順帶查證到的資料涵蓋問題

那唯一一筆交集結果是 Faker 種子資料。查下去：**592 筆 OSM 匯入資料裡有 features 的是 0 筆**
（種子資料 20 筆裡有 14 筆有）。因為 `RestaurantSyncService` 只同步 `dietTypes`，沒有處理
features。所以「寵物友善」「停車」兩個篩選即使修好了，套在真實匯入資料上仍然永遠是空的。
OSM 其實有 `wheelchair`／`outdoor_seating` 之類的標籤可以對應，但要不要收、怎麼對應是
產品決定，沒有擅自加。

### 驗證

前端 74 個測試（新增 13 條：`useFilterQuery` 11 條 + 列表頁 7 條，其中兩條測 `apiFilterParams`），
後端 107 個測試不受影響，Pint／PHPStan／ESLint／vue-tsc／build 乾淨。
反向驗證：把列表頁的 filters 改回 local state → 6 條紅。
瀏覽器實測：帶篩選開頁會回填晶片與徽章、點晶片寫進網址並重查、清除只清篩選而
city／keyword 留著、上一頁回到套用前的狀態。

過程中修掉一個測試 fixture 缺口：列表頁測試檔的 API mock 沒回 `/diets`／`/features`，
FilterDrawer 畫不出晶片，篩選相關測試會測到空畫面。

**未完成 / 等待確認：**

- API 目前不接受 `parking=true` 這種寫法（只接受 `1`/`0`）。對我們自己的前端沒問題，
  但對其他 API 使用端不太友善，要不要放寬是後端 API 設計決定。
- OSM 匯入沒有帶入任何 feature，`pet_friendly`／`parking` 篩選在真實資料上無效。
- 匯入資料的 `address` 常是空字串，卡片會多一行空白（既有問題）。

## 2026-08-25 — OSM 同步帶入 features

上一則的未完成項：592 筆匯入資料裡有 features 的是 0 筆，`RestaurantSyncService` 只同步
`dietTypes`。

**先量再對應，不憑印象。** 直接抓台中 177 筆＋東京 210 筆節點回來統計標籤分布：

| features.code | OSM 標籤 | 台中 | 東京 |
| --- | --- | --- | --- |
| `takeout` | `takeaway` | 45 | 32 |
| `outdoor_seating` | `outdoor_seating` | 1 | 44 |
| `delivery` | `delivery` | 10 | 6 |
| `wifi` | `internet_access` | 2 | 16 |
| `reservation` | `reservation` | 0 | 12 |
| `pet_friendly` | `dog` | 0 | 3 |
| **`parking`** | — | **0** | **0** |
| **`family_friendly`** | — | **0** | **0** |

**統計順便揭露一個很容易寫錯的陷阱：很多標籤的值是 `no`。** `outdoor_seating=no` 有 32 筆、
比 `yes` 的 10 筆還多，`wheelchair=no` 14 筆、`delivery=no` 5 筆。如果照直覺寫成「有這個
標籤就掛上特色」，會把 32 家明確標示沒有戶外座位的店標成有。**把使用者騙去白跑一趟，比
漏收嚴重得多**，所以對應表是「標籤 → 特色 + 值的白名單」，不是單純的 key 對應。

`parking` 與 `family_friendly` 查證後確認 OSM 對餐廳節點沒有通用標註慣例（`capacity:parking`／
`kids_area` 等變體也都是 0），所以不做對應——寧可讓那兩個篩選維持空的，也不硬湊。

**實作**：`RestaurantData` 加 `featureCodes`，`OsmRestaurantProvider::featureCodes()` 依
對應表產生，`RestaurantSyncService::syncFeatures()` 用 `syncWithoutDetaching` 寫入——
跟 diet types 同一套規則（對不上的 code 丟掉），而且**每天的自動同步不會洗掉 Admin 或
使用者手動加上的特色**，OSM 只負責補充它知道的部分。

**實跑五個城市的結果**（0 → 138 筆有特色）：

```
takeout 111 / wifi 19 / outdoor_seating 18 / delivery 14 / reservation 9 / pet_friendly 3
```

順帶把台北補完整了：先前只跑過市中心那組小 bbox（106 筆），這次跑設定裡的完整台北市範圍，
`created 116 / updated 106`——總數 592 → 708。

**驗證**：`pet_friendly=1` 從只有種子資料變成 7 筆（3 筆真的來自東京的 `dog=yes`）；
`parking=1` 仍然只有種子資料、OSM 匯入 0 筆，跟事前預測一致。後端 132 個測試、368 個
assertion 全綠（新增 25 條：provider 21 條含兩組 dataProvider、sync service 4 條）。
反向驗證：拔掉 `syncFeatures()` 呼叫 → 3 條紅。

### 做完才看清楚的落差（重要）

實測 `?takeout=1` 時發現**這個參數根本沒有被處理**。查 `RestaurantRepository::baseQuery()`：
特色篩選只支援 `pet_friendly` 與 `parking` 兩個——**而那正是唯二無法從 OSM 取得的**。
現在的狀況是：

- 有資料但**不能篩**：`takeout`(111)、`wifi`(19)、`outdoor_seating`(18)、`delivery`(14)、
  `reservation`(9)
- 能篩但**幾乎沒資料**：`parking`(OSM 0 筆)、`pet_friendly`(3 筆)

也就是說這次匯入的資料，除了 `pet_friendly` 之外使用者都用不到。要讓它真的可用，需要把
特色篩選改成泛用的（例如 `?feature=takeout` 或 `?features=takeout,wifi`），
`FilterDrawer` 也從寫死兩個晶片改成依 `/features` 動態渲染。這會動到 API 契約與 UI，
是設計決定，沒有擅自做。

**未完成 / 等待確認：**

- **上面那個落差是這次最該接著處理的事**：匯進來的特色目前大多沒有查詢入口。
- `wheelchair` 兩地共 52 筆（東京 49、台中 3）是最豐富的未使用標籤，但 `features` 表沒有
  對應項目，要不要新增是產品決定。
- `internet_access=yes` 被當成 wifi（理論上可能是有線），這是實務判斷，已在程式碼註解說明。

## 2026-08-25 — UI 接上後端已回傳卻沒人用的欄位（距離／評分）

接手時發現 repo 已被另一個 session 推進 6 個 commit（特色篩選接齊 8 碼、純素食店／友善店
分流、venue_scope、菜單葷素、AI Office Phase 1），我上一輪指出的「特色有資料卻沒查詢入口」
已經被補上。所以這輪改成**盤點後端能力與前端顯示的落差**。

**先量涵蓋率再決定做什麼，避免重蹈「停車篩選」覆轍。** 後端支援但前端沒接的有三個：

| 欄位 | 有值筆數 | 其中 OSM 匯入 | 決定 |
| --- | --- | --- | --- |
| `price_level` | 20 / 1159 | **0** | ❌ 不做 UI |
| `rating_min`（篩選） | rating>0 只有 1 / 1159 | **0** | ❌ 不做 UI |
| `distance_meters` | 每次帶座標查詢都有 | — | ✅ 做 |

價位與最低評分做出來會是第二個「停車篩選」——UI 完整、按下去永遠沒結果。只做距離。

**順帶抓到一個一直在說謊的顯示。** 1159 筆餐廳裡只有 1 筆有評分，其餘全部印成
「⭐ 0.0 (0)」——把「還沒有人評分」顯示成「評分 0 分」。同一張卡片上，唯一有用的訊號
（距離）沒顯示，卻塞了一個對 99.9% 的店都不成立的零分。改成 `formatRating()`：
沒有評分就寫「尚無評分」。

新增 `resources/js/lib/format.ts`（`formatDistance`／`formatRating`）讓地圖 popup（字串拼接）
與卡片（template）共用同一份規則。距離一公里內取整到十位——後端回的是 389.4 這種小數，
GPS 本身就有誤差，寫「389.4 公尺」是假精確。

接上四個顯示點：地圖 popup、首頁推薦卡、列表頁卡片、詳細頁評分。

**驗證**：前端 104 → 118 個測試（新增 14 條）。反向驗證：把 `formatDistance` 的公尺分支
與 `formatRating` 的無評分分支拔掉 → **7 條紅**，涵蓋三個顯示點。瀏覽器實測台北首頁
（730 公尺／1.2 公里／尚無評分）、列表頁、詳細頁都正確；地圖 popup 因為點 marker 會直接
導頁，改由元件測試斷言實際傳給 Leaflet 的字串。

**環境注意（不是程式碼問題）**：跑後端測試時 50 個失敗，訊息是
`veggiemap_testing.migrations doesn't exist` 與 `Table already exists`——`git status` 顯示
**另一個 session 正在同一個工作目錄寫 AI Office Phase 2**（16 張未追蹤的 migration、
controllers、models，還改了 `routes/api.php`／`bootstrap/providers.php`／`DatabaseSeeder`），
兩邊同時對共用測試庫跑 migration。這輪我一個 PHP 檔案都沒動，提交時**逐一列出檔案而不是
`git add -A`**，避免把別人進行中的工作掃進我的 commit。

**未完成 / 等待確認：**

- `price_level`／`rating_min` 的 UI 先不做，等真的有資料來源再說（OSM 沒有這兩種標籤，
  評分要等使用者評論累積）。
- 列表頁用 bbox 查詢所以沒有 `distance_meters`，距離只在首頁地圖那條路徑顯示。
  要讓列表也有距離，得在使用者授權定位後把座標一起送出，是另一個題目。

## 2026-08-25 — 修 CI：AI Office health 檢查需要 Redis，CI 沒有

推上前一則的 UI 改動後 CI 後端紅了。我這次零個 PHP 改動，而 CI 是乾淨 checkout，
所以不可能是本機未提交的東西造成——查 `gh run list` 發現 **main 從 `6043905`
（AI Office Phase 1）起就已經是紅的**，我的 commit 只是繼承。

失敗全在 `Tests\Feature\AiOffice\HealthTest`：`Failed asserting that 503 is identical to 200`。
根因：`/api/v1/ai-office/health` 是 readiness 檢查，會真的 `Redis::connection()->ping()`，
而 `.github/workflows/ci.yml` 的 services 只有 MySQL、沒有 Redis，ping 丟例外 → 固定 503。

本機測得過是因為 docker-compose 有 redis container——**乾淨 checkout 才會炸，正是
Phase 12 導入 CI 要抓的那類問題**。本機實跑 `--filter=HealthTest` 確實 7 條全過。

逐項確認 Redis 是唯一缺的，不是改一個猜一個：

- `database`：CI 已有 mysql service ✓
- `queue`：`phpunit.xml` 是 `QUEUE_CONNECTION=sync`，`SyncQueue::size()` 回 0，不需外部服務 ✓
- `workspace`：`git ls-files workspace/` 確認 `.gitkeep` 有進版控，乾淨 checkout 會有這個目錄 ✓
- `redis`：CI 沒有 ✗

修法：CI 加 `redis:7-alpine` service（含 healthcheck），並在 job env 補
`REDIS_HOST=127.0.0.1`——`.env.example` 寫的是 docker-compose 的服務名 `redis`，
在 CI 解析不到。順帶把 workflow 裡「測試套件不需要 Redis」那段已經過時的註解改掉。

**環境注意**：另一個 session 正在同一個工作目錄寫 AI Office Phase 2（16 張未追蹤 migration、
controllers、models）。本次一樣逐一列出檔案提交，沒有用 `git add -A`。

### 更正：上一則把 CI 紅的原因寫錯了

上一則說「根因：CI 沒有 Redis service」——**那個判斷不完整**。補上 redis service 後
CI 仍然 503，再補 ext-redis 擴充（`setup-php` 的 extensions 沒有 redis、composer 也沒裝
predis）之後**還是** 503。

兩次都在設定層猜測、沒有拿到真正的失敗細節。第三次改變作法：把 CI log 完整撈出來確認
redis service 有正常啟動（`Ready to accept connections`）、ext-redis 有 `✓ Enabled`，
逐一排除後回頭讀 health 的四個檢查，才發現真因在 **workspace**：

`.env.example` 寫的是 `AI_OFFICE_WORKSPACE_ROOT=`，那是「已定義的空字串」，
`env('AI_OFFICE_WORKSPACE_ROOT', base_path('workspace'))` 的第二參數不會生效，
`workspace_root` 變成 `''`、`is_dir('')` 為 false → 檢查失敗 → 固定 503。
CI 是 `cp .env.example .env` 所以一定踩到；本機 `.env` 沒有這一行，走預設值，永遠是綠的。

實測驗證機制（不是讀程式碼推論）：`docker compose exec -e AI_OFFICE_WORKSPACE_ROOT= `
跑 config 解析，修前 `workspace_root=''`／`is_dir=false`，修後
`/var/www/html/workspace`／`is_dir=true`。

**這是同一個坑的第二次**——`EXTERNAL_API_OVERPASS_USER_AGENT=` 那次一模一樣。已在
`config/ai_office.php` 註解與新測試裡寫明，並改用 `?:`。

前兩個 CI commit 不是白做的：health 真的會 `Redis::ping()`，workspace 修好之後
若沒有 redis service 與 ext-redis，換成 redis 那項失敗。三個修正缺一不可，
只是我應該先拿到完整失敗資訊再動手，而不是連猜兩次。

CI 現況：`32819998158` 兩個 job 都綠，main 從 `6043905` 紅到 `c87e8e3` 為止。

## 2026-08-25 — 價位／評分篩選、搜尋跨城市、移除消費者端登入入口

三件事都是使用者指定的產品決定。其中兩件我先前給過相反建議，這裡記下決定與理由。

### 1) `price_level`／`rating_min` 接上 UI

我上一則建議**不要做**，理由是資料涵蓋率太低（`price_level` 只有 20/1159 有值且 OSM 匯入
0 筆；`rating > 0` 只有 1 筆），會變成第二個「UI 完整、按下去沒結果」的停車篩選。
使用者看過數字後仍決定要做——那是產品決定，照做。

- `useFilterQuery` 納入這兩個參數，價位晶片用 `$`～`$$$$`，評分提供 3.0／3.5／4.0／4.5
  四個門檻（不做 0.1 級距滑桿——後端是 `between:0,5`，但沒有人會想篩「3.7 分以上」）。
- **網址上的無效值直接忽略而不是原封不動送出去**：`?price_level=99` 送到後端會回 422，
  整個列表變成「載入失敗」。網址是使用者隨手可改的，一個無效參數不該讓整頁壞掉。
- 實測確認篩選真的在篩，不是永遠空：`price_level=1` 回 5 家、不帶則 20 家。
  但 `price_level=4&rating_min=4` 在純素食店範圍內確實是 0 家——如同事前預期。

### 2) 搜尋不受城市範圍限制

原本選了城市就只在該城市 bbox 內找。實測 `keyword=Loving Hut`：

| | 筆數 |
| --- | --- |
| 限台中 bbox（舊行為） | **1** |
| 跨全部城市（新行為） | **5**（台南／東京／台中／台北） |

舊行為會讓人以為別的城市沒有這家店。城市切換是「瀏覽某個地區」的工具，關鍵字搜尋是
「我知道要找什麼」，不該被地區綁住。

有關鍵字時 `bbox` 直接不送，並在畫面上明講「搜尋『X』時會跨全部城市，不受目前選的
『台中』限制」——不能讓使用者以為還在該城市內找。空狀態的建議也跟著調整：已經跨全部
城市了就不再說「切換到其他城市」。清掉關鍵字後城市限制自動回來。

### 3) 移除消費者端登入入口

範圍是使用者選的「只移除消費者端」，不是整套拔掉。**這點很重要**：整個 AI Office 子系統
掛在 `auth:sanctum` 底下、依賴 `users.role`，而另一個 session 正在上面蓋 Phase 2；
收藏／評論／回報／管理後台也全都綁使用者。整套拔會直接打斷別人進行中的工作。

實際改動只有兩處，路由與資料表都沒動：

- 導覽列移除「收藏／個人資料／登入」，保留「管理後台」（admin）與「登出」（已登入者）
  ——後台審核與 AI Office 仍然需要登入，那不是消費者功能。`/login` 路由保留，
  否則管理員無從登入。
- 餐廳詳細頁移除「登入後可以收藏餐廳或寫評論」那段引導。

### 順手修掉一個會讓 CI 前端變紅的問題

跑測試時發現 `Test Files 1 failed` 但 `134 passed`——失敗的是
`vendor/standard-webhooks/.../webhook.test.ts`，composer 套件夾帶的 JS 測試，
還 import 了我們沒安裝的 `@stablelib/utf8`。`vitest.config.ts` 原本沒限定掃描範圍，
所以 composer 一裝新套件就可能把別人的測試撿進來跑。加上
`include: ['resources/js/**/*.{test,spec}.{ts,tsx}']`。

### 驗證

前端 118 → 134 個測試（新增 16 條，含 3 條導覽列測試）。**反向驗證分三項做**：
退回「搜尋受城市限制」→ 6 條紅；拿掉 price/rating 的 API 參數 → 1 條紅；
把登入連結加回導覽列 → **第一次沒有任何測試紅**，代表導覽列改動沒有測試保護，
補了 `App.test.ts` 之後再拔一次才紅。瀏覽器實測三項都確認過。

**未完成 / 等待確認：**

- `price_level`／`rating_min` 的 UI 已經好了，但資料幾乎沒有：價位只有 20 筆種子資料有值、
  評分只有 1 筆。OSM 沒有這兩種標籤，評分要等使用者評論累積——而消費者端現在不需要
  登入，評論怎麼來會是下一個問題。
- 移除的只是入口，`/login`／`/register`／`/favorites`／`/profile` 路由與畫面都還在，
  直接輸入網址仍可到達。要不要連路由一起收掉是另一個決定。

---

## 2026-08-25 — AI Office Phase 7：Activity 事件流 ＋ SSE 即時推送

### 先修掉 Cursor 上一版留下的型別錯誤

接手時 `npx vue-tsc --noEmit` 兩個錯：`formatCuisines()` 的參數宣告成 `{ label: string }[]`，
但 `Restaurant.cuisines` 帶 `code`，TypeScript 的「物件字面值多餘屬性檢查」讓
`format.test.ts` 直接紅。**`npm run build` 的第一步就是 `vue-tsc --noEmit`，所以這是會擋
CI 前端 job 的錯，不是型別潔癖**——`npx tsc` 跑不出正確結果（不認 `.vue`），要用 `vue-tsc`。
修法是把 `Cuisine` 抽成 `resources/js/types/index.ts` 的具名介面，兩邊共用。

Cursor 那一版的後端（`CuisineCatalog`／`AddressFormatter`／OSM `addr:*` 拼地址）
跑過 351 個測試、PHPStan、Pint 全綠，沒有其他問題。

### Phase 7 做了什麼

`ai_office_activities` 從 Phase 3 起就一直有人寫（AgentRuntime／Orchestrator／
CeoPlanner／ApprovalService），但**沒有任何一條路徑讀得到**。這階段補上讀取端：

- `GET /ai-office/projects/{id}/activities`：分頁列表。不帶 `after_id` 由新到舊，
  帶了就只回更新的、且改成由舊到新——這是斷線補漏要的順序，兩種順序混用前端接不起來。
  `meta.latest_id` 讓前端拿到串流起點，不用重收整段歷史。
- `GET /ai-office/projects/{id}/events`：SSE。以自增 id 當游標（不是 `created_at`：
  同一秒可以有多筆，用時間當游標會在毫秒邊界漏送或重送），送 `activity`／`heartbeat`／
  `reconnect` 三種事件，`Last-Event-ID` 標頭優先於 `after_id`。
- Task／Agent 狀態變動用 **observer** 寫進事件流，不是在每個改狀態的地方補一行 `record()`。
  狀態會在 Controller、Orchestrator、Runtime、RetryFailedTaskJob 四處被改，逐處補的話
  日後多一條路徑就會靜靜地少一個事件；observer 看的是「status 欄位髒了沒」。

### 兩個設計決定

**SSE 的認證用一次性票，不是把 token 塞進網址。** `EventSource` 不能帶
`Authorization` 標頭，而 query string 會進 nginx access log 與瀏覽器歷史——把長期有效的
Sanctum token 放進去等於外洩。做法是先用 Bearer token 打
`POST /projects/{id}/events/ticket` 換一張綁使用者＋專案、預設 60 秒、**兌換即作廢**的票。
票在 cache 裡存的是 `hash('sha256', $ticket)`，不是明文。串流路由因此掛在 `auth:sanctum`
群組外面，角色檢查改用兌換出來的使用者在 Controller 內再做一次（票發出後角色可能被降級）。

**連線上限是 429 不是排隊。** implementation-plan 第 13 節列的風險是「長連線佔滿
PHP-FPM worker」，排隊等於同樣佔著 worker。每人預設 3 條，計數放 cache 且帶 TTL
（連線壽命的兩倍）——worker 被 kill 導致 `release()` 沒跑到時，計數會自己過期，
不會把使用者永久鎖在門外。

### 驗證

13 個新測試，全套 **338 → 351 個測試全綠**，PHPStan 0 error、Pint PASS、
前端 140 個測試與 `vue-tsc` 全綠。SSE 測試是真的跑 `streamedContent()` 讓 callback 執行，
不是只斷言標頭。反向驗證：`max_connections_per_user` 設 0 → 429；同一張票用第二次 → 401；
拿別的專案的 id 兌換 → 401；連續開兩條（上限 1）→ 都成功，證明名額真的有還回去。

**沒有做的：** 前端還沒有任何東西訂閱這條串流（那是 Phase 8 的 Dashboard），
所以只在測試環境驗證過，沒有在瀏覽器裡開過真的 `EventSource`。壓測（多少條連線會
壓垮 PHP-FPM）也還沒做——上限值是保守猜測，不是量測結果。

---

## 2026-08-25 — AI Office Phase 8：Vue Dashboard（接上 Phase 7 的 SSE）

Phase 7 把事件流開出來之後，沒有任何前端在讀它。這階段補上 `resources/js/ai-office/`：

```
ai-office/
├── api/         projects / tasks / agents / approvals / events
├── stores/      projects / tasks / agents / approvals（Pinia）
├── composables/ useActivityStream（SSE 連線生命週期）
├── components/  dashboard（CommandCenter, ActivityFeed, StatisticsPanel, ApprovalPanel）
│                task（TaskBoard, TaskCard, TaskDetail）／agent（AgentList, AgentCard）
└── views/       DashboardView / ProjectDetailView / AgentsView / ApprovalsView
```

路由掛在 `/ai-office/*`，`meta.requiresAiOffice` 對應後端的 `ai-office` 中介層；
導覽列的入口只在 `auth.canAccessAiOffice` 為真時出現。配色照規格第 69 節
（`#0B0F14`／`#111820`／`#26313D`），包在 `.ai-office` 這層 class 底下——掛在 `:root`
會把白底綠色的餐廳地圖一起弄黑。

### 三個實作決定

**斷線重連前一定先用 REST 對帳。** SSE 只送連線期間的事件，斷線視窗裡的那些沒有人會補。
`useActivityStream` 每次連線（含重連）都先打 `/activities?after_id=`，再開串流。
反向驗證：把這段拿掉，7 個 composable 測試紅 3 個。

**收到 `error` 就自己關掉重連，不靠瀏覽器的自動重連。** 票是一次性的，瀏覽器拿同一張票
重連只會一路 401，而且它不會自己停——會變成穩定的錯誤迴圈。

**事件只當觸發器，狀態一律重抓。** 收到 `Task*`／`Agent*` 事件時重打任務清單，而不是拿
事件 payload 在前端套用新狀態。真相在後端，前端猜錯就會顯示一個資料庫裡根本不存在的狀態。

還有一個測試逼出來的修正：換不到票而退回輪詢時，原本輪詢成功會把 `error` 清成 null，
畫面就變成「輪詢中」卻不說為什麼。改成把降級原因單獨記著，只清「這次抓失敗」的訊息。

### 驗證

前端 140 → **188 個測試全綠**（48 個新測試），`vue-tsc`、ESLint、`npm run build` 都過。
**反向驗證三項**：拿掉重連前的對帳 → 3 條紅；拿掉路由守衛 → 1 條紅；
拿掉「收到事件就重抓」 → 1 條紅。

**沒有做的：**

- **還沒在真的瀏覽器裡開過這個面板**（本輪決定由使用者自己登入驗），所以「`EventSource`
  在真實 nginx＋PHP-FPM 下會不會被緩衝住」只有後端送了 `X-Accel-Buffering: no` 這個
  預防措施，沒有實測證據。
- Usage／成本面板（規格第 44 節的元件清單有列）留到 Phase 10：後端還沒有 token 用量端點，
  現在做只能寫死數字，那正是規格第 7／38／74 節禁止的事。
- Pixel Office（Phase 9）還沒開始，目前是純資訊面板。

---

## 2026-08-25 — AI Office Phase 9：Pixel Office

`resources/js/ai-office/components/office/` 五個元件（規格第 44／45 節的清單）：
`OfficeMap`（依角色分房）→ `OfficeRoom`（一間房＝一個角色）→ `AgentDesk`（桌子＋螢幕）
→ `AgentCharacter`（像素小人）＋ `AgentStatusBadge`。

掛在兩個地方，資料來源不同：

- **總覽**：只畫「誰在什麼狀態」。那一頁沒有專案脈絡，硬要顯示「正在做什麼」就得挑一個
  專案，那是猜的。
- **專案詳情**：桌上多一行「正在跑的任務」，跟任務看板同一份資料算出來，所以不會發生
  「小人在打字、看板卻沒有 running」這種兩邊對不起來的情況。Agent 狀態事件（Phase 7 的
  `AgentStatusChanged`）進來時整個辦公室跟著換，不用重新整理。

### 三個實作決定

**純 SVG 方塊，`shape-rendering="crispEdges"`。** 規格第 45 節要求 CSS + SVG 不用點陣圖。
少了 `crispEdges`，縮放時邊緣被抗鋸齒糊掉，看起來就不是像素風而是一團模糊方塊。

**狀態不是只有顏色。** 等待核准時小人把右手舉起來（`y` 與 `height` 都變），工作中手臂
上下敲鍵盤，錯誤變紅停住，離線灰階半透明。每張桌子都有 `aria-label` 寫明「誰、正在處理
什麼」，色盲或用讀螢幕的人不會少拿到資訊。動畫全部包在
`@media (prefers-reduced-motion: reduce)` 的關閉條件裡。

**「正在做什麼」只認 `status=running`。** 一個 Agent 同時有多個 running 時取 id 最小的
（最早派的），不隨機挑——同一份資料每次進來要畫出一樣的畫面。

### 驗證

前端 188 → **210 個測試全綠**（22 個新測試），`vue-tsc`／ESLint／`npm run build` 都過。
**反向驗證兩項**：拿掉 running 過濾 → 1 條紅；讓舉手的手臂不動 → 1 條紅。

**視覺確認**：用一頁獨立的預覽頁（同一份 SVG 標記與 CSS）在瀏覽器裡實際看過五種狀態，
第一版小人是「站在桌子上」，把桌子往上疊 0.75rem 之後才變成「坐在桌前」——這是單元測試
永遠抓不到的問題，值得多開那一頁。預覽頁看完就刪掉，沒有留在 repo 裡。

**仍未做的：** 真正的 `/ai-office` 頁面還是沒有在瀏覽器裡登入看過（本輪由使用者自己驗），
所以「像素辦公室在真實資料下的排版」只有元件層級的證據，沒有整頁截圖。

---

## 2026-08-25 — AI Office Phase 10：用量／成本報表、Agent 效能、以及真的接起來的 Agent 記憶

三件事，其中一件是把 Phase 2 就建好卻從來沒人用過的表接上電。

### 1. AgentMemory（規格 §41）從「有表沒用」變成真的有作用

`ai_office_agent_memories` 從 Phase 2 就存在，但整個 repo 裡沒有任何一行寫入或讀取
——查證方式是 `grep -rln AgentMemory app tests`，只有 Model 自己跟 `Agent::memories()`。
這次補上 `AgentMemoryService`：

- 任務完成寫一則 `task_result`，失敗寫一則 `error_pattern`（預設重要度 7 > 5：
  下次接到類似任務時，「上次為什麼掛掉」比「上次做了什麼」更該先被想起來）。
- 執行前把重要度最高的前 N 則放進 prompt。`project_id` 為 null 的是跨專案通則
  （例如使用者偏好），換專案不會忘記。
- 兩道上限在 `config/ai_office.php` 的 `memory`：單則長度、每次取幾則。理由很實際——
  記憶是要塞進 context 的，無上限地塞等於每次請求都在為舊資訊付 token。

順手修掉一個之前就存在的隱患：`initialPrompt()` 原本被呼叫兩次（一次寫進
`task_runs.input`、一次真的送出去）。加了記憶之後兩次的內容可能不同，事後查案就會被
`task_runs.input` 誤導。改成組一次、傳兩處用，並補了一條測試直接斷言兩者相同。

### 2. 用量與成本報表（規格 §38／§40）

`GET /ai-office/usage`：totals ＋ 依模型／Agent／專案分組 ＋ 每日序列，可依專案、Agent、
日期區間篩選。`GET /ai-office/stats/agents`：每位 Agent 的任務數、完成、失敗、重試、
執行次數、成功率、平均耗時、token 與成本。

三個刻意的決定：

- **成本用寫入當下的 `estimated_cost` 加總，不在報表這層重算單價。** 重算的話，改了
  價目表連歷史帳單都會跟著變，對不上當時的實際請求。`meta.pricing` 回傳現行價目表，
  讓畫面能說明數字的來源。
- **金額一律回固定 6 位小數的字串**，不回浮點數。
- **`success_rate` 與 `avg_duration_ms` 在沒有資料時回 `null` 而不是 0。** 兩者意義不同，
  回 0 會讓排行榜把還沒上工的人排到最後一名。平均耗時只算 `status=completed` 的執行，
  否則「失敗得很快」會被算成效率高。

聚合查詢分成四段各自算完再在 PHP 併起來，不寫成一個大 join：任務→執行→用量是一對多再
一對多，join 起來 SUM 會把同一筆用量重複計算——那種錯誤在報表上只是「數字有點大」，
非常難被發現。

### 過程中抓到的兩個真問題

1. **`Column 'created_at' in where clause is ambiguous`（500）**：`by_agent`／`by_project`
   會 join 另一張表，那兩張表也有 `created_at`；**只有在同時帶日期篩選時才會炸**，
   所以很容易漏測。修法是查詢條件一律帶表名，並補一條專門測「日期篩選＋join 同時出現」
   的測試。
2. **PHPStan 抓到把統計列當成 Model 用**：`SUM(...) as total_tokens` 用 Eloquent 取回來時，
   `$row->total_tokens` 是模型上不存在的動態屬性——執行期安靜地能跑，但型別上是錯的。
   改成 `->toBase()`，聚合結果就是 stdClass 列。

### 3. 前端 `/ai-office/usage`

總計磚、每日 token 長條圖（純 CSS 高度，沒有引第三方圖表套件——一張長條圖不值得多背一個
相依，也不用擔心它的預設配色跟深色主題打架）、依模型／依專案清單、Agent 效能表。
Agent 詳情頁多了「記得的事」，會進下次 prompt 的那幾則用左側綠線與不透明度標出來
——「記得很多」跟「真的會用到」是兩件事。

### 驗證

後端 351 → **375 個測試全綠**，前端 210 → **220 全綠**，PHPStan 0 error、Pint PASS、
`vue-tsc`／ESLint／`npm run build` 都過。**反向驗證三項**：拿掉 prompt 裡的記憶區塊 → 1 條紅；
把 null 成功率改成 0 → 1 條紅；把長條圖高度寫死成 100% → 1 條紅。

**仍未做的：** 一樣沒有在瀏覽器裡登入看過（使用者自己驗）。另外 `AgentMemory` 目前只有
自動寫入與唯讀 API，沒有「人工新增／刪除一則記憶」的端點——規格沒有要求，而且開放寫入
等於開放任意注入 prompt 內容，先不做。

---

## 2026-08-25 — AI Office Phase 11：Docker 沙箱（指令真的進容器跑）

Phase 5 起 `TerminalTool` 的規則是「沙箱沒就緒就拒絕執行，不退回 host 跑」，所以
`AI_OFFICE_SANDBOX_ENABLED=true`（預設）之下 Terminal 工具其實一直是**完全不能用**的。
這階段把沙箱真的做出來，但**沒有放寬那條拒絕規則**。

### 三個元件

- `ProcessRunner`／`SymfonyProcessRunner`：執行外部程序的抽象。抽介面不是為了「好抽換」，
  是為了能斷言**我們到底送了哪些參數給 docker**——沙箱的安全性幾乎全在那串旗標上，
  而它在容器跑起來之後就看不見了。argv 直接 exec，不經過 shell。
- `SandboxManager`：組 `docker run` 的參數、偵測 docker 可用性（結果快取在實例上，
  一次任務會問很多次）、逾時後強制移除容器（`--rm` 只在正常結束時生效）。
- `DockerSandboxEngine`：Docker 工具的真引擎，**預設仍然關閉**
  （`AI_OFFICE_SANDBOX_DOCKER_TOOL=false`）——讓 Agent 能建立與啟動容器，比跑一條白名單
  指令高一級，不該因為升級到 Phase 11 就自動生效。

`SandboxPolicy` 從一個布林值改成三態：`host`（沙箱被明確關掉）／`sandbox`（開著且 docker
可用）／`refuse`（開著但 docker 不可用）。三態化之後才發現 `DockerTool` 原本用
`hostExecutionAllowed()` 當守門，沙箱正常運作時反而會被自己擋下來——一併修掉。

### 每一條旗標對應一種具體攻擊

`--network none`（LLM 產生的指令有網路就等於有外送資料的管道）、`--cap-drop ALL`、
`--security-opt no-new-privileges`（擋 setuid 提權）、`--read-only` + `--tmpfs`（只有
`/workspace` 與 `/tmp` 可寫）、`--user 1000:1000`（非 root，寫進 workspace 的檔案也不會變成
root 所有）、`--pids-limit`（fork bomb 的第二道防線，第一道是 CommandAllowlist）、
`--memory`／`--cpus`，以及**只掛專案 workspace，不掛 docker.sock、不掛 host 根目錄**。

### docker.sock 這個權衡，寫下來而不是偷偷做

要讓 app container 能開容器，就得掛 `/var/run/docker.sock`——**那實質上接近把 host root
交出去**（可以掛 `/` 進新容器）。所以這件事沒有寫進 `docker-compose.yml`，而是另開一份
`docker-compose.sandbox.yml`，檔頭把權衡講清楚：用一個較大的信任邊界，換掉「LLM 產生的
指令直接在 app container 裡跑」這個更糟的狀態；不掛就是 unavailable，維持拒絕執行的安全
預設；不要在多租戶或不受信任的環境用。更嚴格的做法（rootless docker、DinD、gVisor／Kata）
留到真的要對外服務時再處理。

### 驗證

後端 375 → **386 個測試全綠 ＋ 4 個 skipped**（11 個單元測試斷言 argv、4 個整合測試真的把
容器跑起來）。整合測試在本機 app container 裡沒有 docker CLI 所以 skip——skip 是誠實的
「沒驗到」，比用假 runner 假裝驗過好；GitHub Actions 的 ubuntu runner 有 docker，CI 會真的
跑到，所以 workflow 多了一步預先 `docker pull alpine:3.20`（不 pull 的話，第一個測試要在
自己的逾時內完成下載，失敗時看起來會像「沙箱壞了」而不是「網路慢」）。

**在 host 上用真 docker 實測過同一組參數**（因為 PHPUnit 跑在沒有 docker 的容器裡）：
`echo` 有輸出、`/proc/net/route` 只剩標頭一行（真的沒有網路）、`touch /etc/nope` 回
`Read-only file system`、寫進 `/workspace` 的檔案真的出現在 host 的目錄裡、`id` 回
`uid=1000 gid=1000`。

**反向驗證三項**：拿掉 `--network none` → 1 條紅；拿掉 `TerminalTool` 的 refuse 分支 →
2 條紅（新舊測試各一）。

**仍未做的：** 沒有在真實 Agent 執行流程裡端到端跑過一次「LLM 要求 `execute_command`
→ 沙箱回結果」（那需要掛 socket 的環境＋一個真的專案 workspace）；`docker-compose.sandbox.yml`
本身也還沒有人實際起過。目前的證據是單元測試（參數正確）＋ host 實測（參數有效），
中間那段接線只有型別與測試保證。

---

## 2026-08-25 — AI Office Phase 12：完整 Demo（12 個 Phase 到此全部完成）

`php artisan ai-office:demo` 跑規格第 79 節的 Todo API 情境：一句需求 → CEO 拆成四個有相依
關係的任務 → backend／qa／devops 依序執行、用 `write_file` 真的把檔案寫進 workspace →
最後一步撞到核准停下來 → 人核准 → 自動接著跑完 → 專案 `completed`。全程假模型
（`DemoScriptProvider`），一個字都不會送到真的 Claude API。

這是整個子系統第一次**端到端**跑完，也因此抓到三個只有在完整鏈路下才會現形的問題。

### 一、Phase 10 的記憶把 Demo 腳本比對污染了

腳本原本用 `str_contains(整段 prompt, 任務標題)` 決定回什麼。Phase 10 之後 prompt 尾巴會
附上 Agent 的記憶——「任務『設計 Todo 資料表』完成：…」——所以第二個任務的 prompt 裡同時
含有自己的標題**和第一個任務的標題**，比中了前者的腳本、拿到它的收尾句，於是安靜地什麼
都沒做就「完成」了。症狀只是 workspace 少了一個檔案，四個任務全都顯示 completed。
改成只認 prompt 第一行的 `任務：<標題>`。

這個坑值得記：**兩個各自正確的功能（記憶、腳本比對）湊在一起才錯**，而且錯得很安靜。

### 二、容器解析順序讓指令版的 Demo 規劃直接失敗

`handle(DemoRunner $runner)` 的 method injection 會在 `handle()` 執行**之前**就把
DemoRunner → AgentOrchestrator → CeoPlanner → LlmProviderInterface 一路解析完。所以在
`handle()` 裡才切換 provider 太晚了：Planner 手上握的仍是預設的 MockProvider（佇列空的），
規劃丟例外、專案變成 failed。改成先 `bootstrapEnvironment()` 再 `app(DemoRunner::class)`。
測試版本沒中，因為它在 setUp 就切好了——**同一段邏輯在測試裡對、在指令裡錯**。

### 三、一開始寫了一條永遠會綠的測試

「任務照相依順序跑」原本斷言 `B.started_at >= A.completed_at`。四個任務全都沒跑時兩邊都是
null，`null >= null` 為 true，測試照樣綠——正是「反向驗證」要抓的那種假保護。
補上四個時間戳都不可為 null 的前置斷言之後，這條測試才真的守得住東西。

### 核准那一步為什麼用「權限層級」而不是降低全域門檻

第一版把 `approvals.threshold` 降成 medium，結果四個任務每一個寫檔都要人按，示範變成
點四次核准。改成只把 Demo 的維運 Agent 的 `write_file` 權限設成 `approval`（規格第 22 節的
另一條路徑），門檻維持預設 high：其他任務照常跑，只有最後一步停下來等人。

也沒有用天生就要核准的 `deploy_staging`：那個能力到現在仍然沒有對應的工具，核准之後只會
得到「工具尚未實作」、任務直接失敗——那示範的是缺口，不是流程。

### 驗證

後端 386 → **398 個測試全綠 ＋ 4 skipped**（12 個新測試：7 個流程、5 個指令層）。
PHPStan 0 error、Pint PASS。**反向驗證兩項**：把腳本比對改回 `str_contains` → 1 條紅；
拿掉核准後的 `tryDispatch` → 3 條紅。

PHPStan 過程中還擋下一個我自己寫錯的東西：它說 `$task->agent?->name ?? …` 的 `?.` 多餘，
但 `assigned_agent_id` 其實是 nullable——照它說的拿掉就會變成對 null 取屬性。改用明確的
三元判斷，並把理由寫在該行上面，免得下次有人又「照 PHPStan 說的改」。

**仍未做的：** 這個指令還沒有在開發資料庫上真的跑過（會寫 DB 與 workspace 檔案，要先得到
同意）。目前的證據是測試資料庫上的端到端驗證。

---

## 2026-08-26 — 營業時間與 `open_now`（搜尋強化第一項）

依使用者指示重新對照兩份總 Prompt 後，把「跟搜尋有關的缺口」排到最前面（會員／評分
評論相關的待辦依指示全部剔除，不做也不擴充）。第一項是 `open_now`——總 Prompt 第八、
二十八節都明寫，schema 卻完全沒有營業時間，`docs/api.md` 一直列著一個不存在的參數。

### 為什麼是獨立資料表而不是一個字串欄位

`open_now` 是**搜尋條件**。把 OSM 的 `opening_hours` 字串存進 `restaurants` 再撈出來
用 PHP 逐筆判斷，就是總 Prompt 第九節明講禁止的「全部撈出來再算」，而且分頁會壞掉
（先分頁再過濾＝每頁筆數不一）。所以解析在**寫入端**做一次，存成
`restaurant_opening_hours(day_of_week, opens_at, closes_at)`，查詢端只剩
`day = ? AND opens_at <= ? AND closes_at > ?` 這種吃得到複合索引的整數比較。

跨午夜也在寫入端切好：`Sa 17:00-02:00` → 「週六 1020–1440」＋「週日 0–120」兩列。
查詢端因此完全不需要處理跨日比較。

### 解析器刻意只做子集

`App\Support\OpeningHours` 只認 `24/7`、`Mo-Fr 11:00-14:00,17:00-21:00`、時間單獨一段
（＝每天）、`Su off` 覆蓋、跨午夜、`PH off`（節慶規則跳過不套用）。月份區間、週序
`Mo[1]`、`sunrise-sunset`、`09:00+`、自由文字一律回 `null`。

理由：完整的 opening_hours 是一套獨立的小語言，為了一個篩選去實作整套不划算，而
**錯解比不解更糟**——會把打烊的店標成營業中。回 null 代表「沒有可信資料」，一路
誠實傳到 UI（`open_status: unknown`，前端不顯示任何狀態文字）。

### 三態而不是布林

API 回 `open_status: open|closed|unknown`。OSM 多數餐廳根本沒有 `opening_hours` 標籤，
把 unknown 壓成 `false` 會讓使用者以為整批店都關門了。同理 `open_now=1` **不會**把
未知的店算進來——寧可漏掉，也不要把打烊的店推給使用者。這條在後端與前端各有一個
測試守著。

### 時區：台北與東京差一小時

`restaurants.timezone` 在同步時依座標落在哪個 `config/cities.php` 的 bbox 決定
（新增 `timezone` 欄位到城市設定）。`applyOpenNow()` 依時區分組，每組各自算出
「當地現在是星期幾、第幾分鐘」再組成 OR 條件——一次 SQL 比完所有時區，不必分開查。
`timezone` 為 NULL 的既有資料跟著 `config/veggiemap.php` 的預設時區走，不會整批從
結果裡消失。

`OpeningStatus::for()` 顯示端也用同一套當地時間，不是伺服器時間。

### 重跑同步是覆寫不是累加

`OpeningHoursService::sync()` 先刪光該餐廳的舊時段再寫入。累加的話，店家改成週日
公休之後舊的週日時段會永遠留著，`open_now` 會在週日把它算成營業中。解析失敗時同樣
清空——「沒有可信資料」要表現成查不到時段，不是留著上一版。這條有專門的測試
（`test_resync_replaces_old_opening_hours_instead_of_accumulating`）。

### 驗證

後端 398 → **428 個測試全綠 ＋ 4 skipped**（22 個解析器單元測試、8 個 HTTP 測試、
2 個同步測試）。Pint PASS、PHPStan 0 error。前端 220 → **225 個測試全綠**，
vue-tsc／ESLint 乾淨。

**反向驗證**：把 `applyOpenNow()` 的呼叫改成 `if (false)` → 8 條裡紅 4 條。

HTTP 測試全部用 `CarbonImmutable::setTestNow()` 把時間釘死。不釘的話同一組斷言半夜
跑會整批反過來，變成「有時綠有時紅」的假保護——這正是坑卡 `negative-assertion-wrong-path`
那一類。

**仍未做的：** 沒有真的重跑一次 OSM 同步把既有 138 筆餐廳的營業時間灌進開發資料庫
（會寫真實資料，且要打 Overpass）。目前 `restaurant_opening_hours` 在開發庫是空的，
證據是測試資料庫上的端到端驗證。要看實際效果需要跑 `php artisan restaurants:sync`。

PHPStan 過程中擋下兩個我寫錯的東西：`$restaurant->openingHours` 是 Eloquent 關聯，
永遠不會是 `null`（我多寫了 `=== null` 判斷），以及 `array_values()` 對已經是 list
的陣列沒有作用。

---

## 2026-08-26 — 關鍵字搜尋強化（搜尋強化第二項）

原本的 `keyword` 只是 `name LIKE %kw% OR address LIKE %kw%`，而且列表頁還把
`sort` 寫死成 `newest`——搜尋結果的順序跟關鍵字完全無關。

### 比對範圍：店名不是使用者真正在搜的東西

素食使用者常打的是「拉麵」「滷味」「泰式」，那些是**菜色**與**料理種類**，不是店名。
`KeywordSearch::applyTo()` 因此比對店名、地址、城市、行政區、描述、`menu_items.name`、
`features.label`／`code`（料理種類就存在 features 裡）。

多詞是 AND、單一欄位是 OR：「台中 拉麵」要兩個詞都命中，但各自可以命中不同欄位。

### WHERE 與相關性必須用同一組欄位

兩者拆在不同地方遲早會漂移——加了菜色比對卻忘了給分，使用者搜「滷味」找得到店
但排在第 40 名。所以兩段程式碼放在同一個類別 `App\Repositories\Search\KeywordSearch`
裡，互相看得到。

權重：店名完全相同 100 > 店名開頭 60 > 店名包含 40 > 菜色 25 > 料理種類 20 >
地區 10 > 描述 5。用 CASE 加總而不是 MySQL 全文檢索：中文全文檢索需要 ngram parser
與 FULLTEXT 索引，對數百筆資料沒有效益，卻讓「店名要贏過地址」這種產品判斷變得
不可控。

相關性同分時退回距離、再退回 id。不加這層的話同分順序由 MySQL 決定，cursor 分頁
翻頁時同一家店可能出現兩次或整個消失。

### 三個實際踩到的地方

1. **`%` 沒跳脫**：搜「100%純素」會退化成萬用字元查詢，撈回一堆不相干的店。
   `escapeLike()` 處理 `\`、`%`、`_`。
2. **長度門檻不能讓整個關鍵字消失**：第一版打一個字元會讓 `terms` 變空陣列，
   結果是「不過濾」＝回傳全部餐廳，看起來像搜尋壞掉。改成全部被砍光時退回原字串
   當單一詞——寧可回 0 筆，也不要回全部。門檻的角色因此縮小成「砍掉多詞查詢裡的
   雜訊詞」，這點寫進註解與 config 說明。
3. **`fromSub` 的 binding 順序**：distance 與 relevance 都是 SELECT 出來的計算欄位，
   兩個都在時 bindings 必須跟 SQL 字串裡 `?` 的出現順序一致。錯位的症狀不是報錯，
   是排序看起來隨機——所以補了一條斷言「`?` 數量 == bindings 數量」的單元測試。

### 前端：首頁地圖原本是死路

打「拉麵」→ SearchBox 呼叫 geocode → Nominatim 查不到地點 →「找不到符合的地點」。
後端明明搜得到，使用者卻走不到。改成候選清單**永遠**先放一句「搜尋餐廳「X」」，
選它就把 keyword 寫進網址、重查、並把地圖視角收到命中的餐廳上。

視角只在「關鍵字的值變了」時收一次（`lastFittedKeyword`）。每次載入都收會出事：
載入是 `moveend` 觸發的，fitBounds 又會觸發 `moveend`——無限迴圈。記成 `string|null`
而不是 boolean，是為了讓「分享連結進來時 keyword 已經在網址上」那條路徑也收一次
視角，那正是使用者最需要它的時候。

### 驗證

後端 428 → **446 個測試全綠 ＋ 4 skipped**（9 個 HTTP、7 個斷詞單元、
1 個 binding 對齊、其餘沿用）。Pint PASS、PHPStan 0 error。
前端 225 → **233 個測試全綠**，vue-tsc／ESLint 乾淨。

**反向驗證兩項**：把店名的三個權重歸零 → 2 條紅；拿掉菜色比對 → 1 條紅。

**仍未做的：** 沒有在瀏覽器上實際點過首頁的「搜尋餐廳」流程（開發資料庫裡的餐廳
沒有 `menu_items`，菜色比對在真實資料上會是 0 命中）。目前證據是測試資料庫上的
HTTP 測試與元件測試。

---

## 2026-08-26 — 素食可信度變成搜尋條件（搜尋強化第三項）

可信度分數從 Phase 6 就存在，但使用者只能在詳情頁看到一個數字——沒辦法用它篩、
不能用它排，列表卡片甚至根本不回這個欄位。對一個叫 VeggieMap 的產品來說，
「只看有把握是素食的店」是最核心的需求，卻沒有入口。

- `confidence_min=N`：可信度下限。
- `sort=confidence`：依分數排序。用 correlated subquery 取 `COALESCE(score, 0)`
  而不是 join——join 會把沒有分數列的餐廳整批濾掉，那些店應該當 0 分排最後，
  不是消失。
- 列表 eager load `confidenceScore`，卡片顯示分數。

### 門檻放 config，不放 Vue

`config/vegetarian.php` 的 `confidence_filters`（30＝有查證、60＝高度可信）經
`GET /diets` 的 `meta.confidence_filters` 給前端，FilterDrawer 只負責渲染。

理由寫在 config 註解裡：`external_source` 是每家 OSM 匯入的店都有的 10 分底分，
門檻必須高於它才有意義；而這個數字會跟著 `verification_weights` 一起變——
`admin_verified` 從 30 調成 40 時，「有查證」的門檻也該動。兩份數字分開維護遲早對不上。

前端有一條測試守著「沒有回 `confidence_filters` 時整組不渲染」——不要用寫死的
預設值硬撐出一個看起來能用、實際上跟後端不同步的 UI。

`formatConfidence()` 對 0 分回 null：0 分跟「還沒有人查證過」在使用者眼裡是兩件事，
印「0 分」看起來像這家店被判定不可信。

### 驗證

後端 446 → **450 個測試全綠 ＋ 4 skipped**，Pint PASS、PHPStan 0 error。
前端 233 → **238 個測試全綠**，vue-tsc／ESLint 乾淨。

---

## 2026-08-26 — 搜尋自動完成（搜尋強化第四項）

先前使用者必須「猜完整的店名」才搜得到東西：輸入框只在按 Enter 時打 geocode，
打對一半沒有任何回饋。`GET /restaurants/suggest` 補上這段。

### 為什麼回三種型別，而不是一串店名

打「日式」時，使用者要的是**一次選起「日式料理」這個分類**，不是看到五家碰巧
店名有「日式」的店。所以建議分成三組，各自對應不同的後續動作：

- 店名 → 直接跳那家店的詳情
- 料理種類 → 用該標籤做一次關鍵字搜尋
- 行政區 → 用該區名做一次關鍵字搜尋

料理種類**只回實際上有餐廳掛著的分類**（`whereHas('restaurants')`）——建議一個
點下去 0 筆的分類等於騙使用者，這條有專門的測試。行政區直接查 `restaurants` 的
既有值而不是寫死清單：涵蓋範圍是由匯入資料決定的。

### 沒有重用 search()

建議清單要的是三種不同型別的候選，而 `search()` 只回餐廳列。硬湊的話上面那個
「分類」建議就做不出來。但相關性排序是共用的：`RestaurantSuggestionRepository`
直接用 `KeywordSearch::relevanceExpression()`，不會出現「搜尋跟建議排序不一樣」。

建議查詢只 `select(['id','name','city','district'])`——自動完成是每打一個字就查
一次的路徑，撈整列（含 `description`／`location`）特別浪費。

### 前端：debounce ＋ 序號雙保險

不節流的話「台中一中街」六個字就是六次請求。debounce 250ms 減少請求數，序號
（`suggestSeq`）保證只有最後一次的回應會被採用——慢的舊回應不會蓋掉新的。

建議 API 失敗時**安靜地不給建議**，不設 error：跳一個紅字說「建議載入失敗」只會
干擾打字，而且使用者仍然可以直接按搜尋。

**沒有把城市帶進建議查詢**：城市切換器顯示的是「台中」，而 `restaurants.city` 存的
是「台中市」（還有「臺中市」與大量空字串，見 `LookupController::cities` 的註解）。
拿顯示標籤去比對會把建議整批濾光。API 本身支援 `city` 參數，留給資料乾淨的使用端。

### 路由順序

`/restaurants/suggest` 必須排在 `/restaurants/{restaurant}` 前面，否則會被當成一家
id=suggest 的餐廳而 404。有一條測試明確守著這件事。

### 驗證

後端 450 → **458 個測試全綠 ＋ 4 skipped**，Pint PASS、PHPStan 0 error。
前端 238 → **243 個測試全綠**（含用 fake timers 驗證 debounce 真的只發一次請求），
vue-tsc／ESLint 乾淨。OpenAPI 過 `@redocly/cli lint`。

---

## 2026-08-26 — 列表欄位收斂與外部 API 斷路器

兩件互不相關但都屬於「規劃明寫、一直沒閉環」的項目。

### 列表不再 SELECT *（第三十二節）

`RestaurantRepository::LIST_COLUMNS` 明列欄位，排除 `description`（TEXT，卡片不顯示）、
`source_id`（只有去重用得到）、`opening_hours`（列表要的是解析後的狀態，走關聯）、
`location`（POINT 二進位，距離已經另外算成 `distance` 欄位）。

`location` 沒被 SELECT 出來不影響半徑搜尋——WHERE 與計算欄位仍然讀得到它。

**關鍵是 Resource 那邊用 `whenHas()` 而不是直接取值**：Eloquent 對沒撈到的欄位
回 null 而不是報錯，所以直接寫 `$this->description` 會讓列表宣稱「這家店沒有描述」。
`whenHas()` 讓那個 key 整個消失，前端與 API 使用端因此分得出「沒有」與「沒撈」。
有一條測試同時斷言「列表沒有這個 key」與「詳情仍然有值」。

### 斷路器（第二十節）

timeout／retry／log／fallback 早就有了，缺的是「連續失敗後不要再空等」。

排程一次跑五個城市 bbox，那是**五個獨立的 artisan 程序**。Overpass 掛掉時，五個
程序會各自 retry 三次、各自等滿 30 秒逾時——十五次注定失敗的請求。所以狀態必須存
在 Redis 而不是程序記憶體，存在物件裡的計數器在這個場景等於沒有（有一條測試明確
用兩個不同實例驗這件事）。

Nominatim 也接上，而且價值更高：它在**使用者請求路徑上**，掛掉時每個搜尋都要等滿
逾時×重試才回錯，體感是整個網站卡住。開路期間直接丟 `GeocodingUnavailableException`，
呼叫端既有的 fallback 會把它轉成空結果——測試明確斷言使用者拿到的是 200 空清單而
不是 500。

只做 closed／open 兩態，沒有 half-open 的試探請求：冷卻到期就放行下一個請求，
成功歸零、失敗重新開路。效果接近 half-open，少一套狀態機。開路的每次短路仍然寫
一筆 `error_code = CIRCUIT_OPEN` 的 log——不然「今天為什麼沒同步」查不到。

### 驗證

後端 458 → **465 個測試全綠 ＋ 4 skipped**，Pint PASS、PHPStan 0 error。
**反向驗證**：把 Overpass 的開路判斷改成 `if (false)` → 1 條紅。

---

## 2026-08-26 — 重複餐廳的 Admin 審核（第二十二節閉環）

`is_possible_duplicate` 從 Phase 8 就會被寫入（同步時「同名＋距離 <100m」把兩筆
都標起來），但在這之前**沒有任何地方看得到這個標記**——標了沒人看等於沒標。

### 為什麼沒有「合併」

規格寫「不要自動刪除，標記供 Admin 審核」，我再往前想一步：連手動合併也不做。
兩筆同名又相近，也可能是同一條街上的兩家分店（台中的連鎖素食店就有這種情況）。
合併會把一家真實存在的店從地圖上抹掉，而且不可逆。

Admin 能做的只有兩件事：
- `keep` — 這筆留著，清掉標記
- `deactivate` — 這筆是重複的，`status` 改 `inactive`

下架而不是刪除：判斷錯了救得回來，`reviews`／`favorites` 的外鍵也不會跟著消失。
前端與 FormRequest 兩邊都沒有 merge 這個選項，不是「先不做」而是刻意不提供。

### 分組不能只看 `name`

全台灣可能有五家同名的素食店，它們不是重複。所以先依 `name` 分組，再在組內
依 100m 貪婪分群——跟 `flagPossibleDuplicates` 用同一個門檻常數的語意。
標記過的筆數本來就少、同組通常就兩筆，不需要完整的聚類演算法。

### `stale` 這個狀態

同組另一筆被處理掉之後，剩下那筆的標記就是過期的。GET 裡**不偷偷改資料**，
而是回一個 `stale: true` 讓 Admin 一鍵清掉。前端有一條測試守著它會被標示出來，
免得看起來像「還有一筆重複沒處理」。

### route model binding 的陷阱

`Restaurant::resolveRouteBinding()` 只認 `status = active`（Phase 5 為了擋
pending 餐廳加的）。這個清單本來就會包含已下架的重複筆，用預設 binding 的話，
要清掉一筆已下架餐廳的過期標記會直接 404。改成在 Controller 裡自己
`findOrFail`，並補一條測試。

順帶補上 `RestaurantPolicy`——`docs/api.md` 從 Phase 11 就列了它，但檔案一直
不存在。現在是真的有了（只管 admin 的重複審核，餐廳沒有公開寫入端點）。

### 驗證

後端 465 → **472 個測試全綠 ＋ 4 skipped**，Pint PASS、PHPStan 0 error。
前端 243 → **248 個測試全綠**（`AdminView.test.ts` 是這個檔案的第一組元件測試，
順便補上 todo 裡「AdminView 仍無元件測試」那一項的一部分）。

---

## 2026-08-26 — 詳情頁走 slug（第二十六節閉環）

規劃寫的路由是 `/restaurants/{slug}`，實作一直是數字 id。

改成**兩種都收**而不是換掉：既有的前端連結、使用者分享出去的網址、以及一批
測試都用數字 id，直接換掉會全部斷。純數字視為 id、其餘視為 slug——`slug` 欄位
本身不可能是純數字（`uniqueSlug()` 的 fallback 是 `osm-node-123` 這種形狀），
不會有歧義。

### 快取要清兩份

`findForDetailBySlug()` 的 cache key 用 slug 而不是「先查 id 再轉」——先查 id 就
白做了快取（進 controller 前已經打過一次 DB），那正是 `findForDetail()` 當初
不用 route model binding 的理由。

代價是同一家餐廳有兩份快取，所以 `RestaurantCacheInvalidator` 必須兩個 key 都清。
沒清 slug 那份的話，`/restaurants/{slug}` 會繼續吐 600 秒的舊資料——而那正是前端
在用的那條路徑，等於快取失效對使用者完全沒生效。**反向驗證**：拿掉清 slug 快取
那段 → 1 條紅。

### 仍未做

中文店名的 slug 還是 `osm-node-123` 這種形狀（`Str::slug()` 音譯不了中文）。
要有真正可讀的中文別名得接拼音轉換，那是另一件事，不在這次範圍——但至少
`/restaurants/shi-fang-zhai` 這種英文可音譯的店名現在是可讀的。

### 驗證

後端 472 → **477 個測試全綠 ＋ 4 skipped**，Pint PASS、PHPStan 0 error。
前端 248 → **250 個測試全綠**，vue-tsc／ESLint 乾淨。

---

## 2026-08-26 — 瀏覽器實測抓到的兩個問題

前面四批都只有測試綠燈，這次真的把 `http://localhost:8080` 開起來點過一遍，
抓到兩個測試看不出來的問題。

### 一、首次渲染送出寬度為 0 的 bbox，畫面閃「載入失敗」

實測到的請求：`bbox=25.0019,121.5654,25.0641,121.5654`——`minLng === maxLng`。
地圖容器在掛載當下還沒量到寬度，`getBounds()` 回傳的是一條線。後端回 422，
畫面閃一下紅色的「載入失敗，移動地圖可重新嘗試」再自己修正。

看起來像壞掉，實際上只是還沒量到尺寸。`emitBounds()` 現在對退化的 bbox 直接不送，
等 `moveend` 之後那次。修完重新載入，網路面板從「兩對 422 再兩對 200」變成
只剩 200。

### 二、建議清單五筆都叫「素食」，看不出差別

打「素食」跳出五筆一模一樣的「素食」。原因是 OSM 匯入的資料有一批
`city`／`district`／`address` 全是空的，而建議清單原本只回 `city`／`district`。

兩層修法：

1. 建議也回 `address`（與 `slug`），顯示時 `city district` → `address` →
   「地址未提供」逐層退回。
2. **同分時，說得出在哪裡的排前面**。這不是排序偏好而是「哪一筆對使用者有用」——
   一筆連地址都沒有的「素食」放在清單第一位，使用者無從選起。

實測結果從「五筆 None」變成前三筆帶臺中市／彰化縣與路名。

這兩個都是「測試綠但實際不對」的類型：第一個要有真實的版面尺寸才會發生，
第二個要有真實的髒資料才看得出來。兩個都補了測試（退化 bbox 不送、
有地址的排前面）。

### 驗證

後端 477 → **479 個測試全綠 ＋ 4 skipped**，前端 250 → **253 個測試全綠**。
`npm run build` 通過，並在瀏覽器重新驗證過修正結果。

---

## 2026-08-26 — API response time、CVE 緩解，與一批過時文件

### API response time（第三十五節的「至少」清單）

`LogSlowApiRequests` middleware 掛在整個 `api` group 最前面。

三個刻意的取捨：

1. **只有慢的才寫 log**（預設 1000ms，config 可調）。每一筆都寫，在有流量時等於
   自製一個成本很高又沒人看的 APM，而這個專案沒有 log 聚合服務去消化它。
2. **每一筆都帶 `X-Response-Time-Ms` 標頭**。這樣「量得到」與「記下來」分開：
   壓測與手動排查不必先去翻 log。
3. **log 記 route 樣板、不記 query string**。逐筆 id 會變成幾千個獨一無二的字串，
   聚合不起來；而 query string 裡有使用者搜尋的關鍵字與座標，屬於個人資料。

不寫進資料表：那需要一張會無限成長的表與清理排程。

### CVE-2026-48019：這次差點寫出一組假保護

`App\Rules\SafeEmail` 掛在所有吃 email 的 FormRequest 上，擋掉控制字元。

**第一版測試是假的**。我用的 payload 是 `user@example.com\r\nBcc: victim@...`，
測試綠——但**把規則整個拿掉，測試照樣綠**，因為那個字串 Laravel 11.56 的預設
`email` 規則本來就會擋。反向驗證當場抓到。

實際去問驗證器才找到真正會通過的形狀：**帶引號的 local part**
`"user\r\n"@example.com`（RFC 5321 的 quoted-string 允許裡面出現這些字元）。
換成它之後，拿掉規則會紅。

第二個假保護在同一組測試裡：登入的測試只斷言 422，但**登入失敗本來就回 422**
（「帳密不正確」也是丟 `ValidationException`），所以那條測試對規則在不在完全不敏感。
改成同時斷言回應裡沒有「credentials are incorrect」——證明它是在驗證階段被擋下，
而不是走到比對密碼那一步。修完之後拿掉規則會紅 2 條。

**這是緩解不是根治**：`composer audit` 仍會報三則 laravel/framework 公告，修補版本
是 12.61.1+／13.12+，屬於 major upgrade，是獨立的工作。文件寫成「已緩解、未根治」，
不寫成已修補。

### 過時文件

`deployment.md` 的缺口表還在說「沒有 Horizon／沒有 `users:promote`／沒有排程」——
三項都在 8/25 做完了；內文的 admin 帳號那段還教人用 `tinker` 手動改 DB。
`observability.md` 的 Queue 段落還停在 `dispatchSync()` 的年代。
`api.md` 列了 `FavoritePolicy`／`ReportPolicy` 兩個不存在的檔案。三份都對齊現況。

### 驗證

後端 479 → **485 個測試全綠 ＋ 4 skipped**，Pint PASS、PHPStan 0 error。
**反向驗證**：拿掉 `SafeEmail` 的判斷 → 2 條紅（修正 payload 與斷言之後才有的結果）。

---

## 2026-08-26 — CI 從 Phase 8 起就一直是紅的（本次接手才發現）

推完前面幾批之後才去看 GitHub Actions——**從 2026-08-25 的 Phase 8 到現在，
每一次 push 都是紅的**，我這次接手時沒有先確認基線就開始加功能，這是流程上的疏失
（先前的紀錄裡也沒有人提到 CI 紅了）。

### 真因：一條依賴機器時區的測試

```
AssertionError: expected '08/25 09:42' to be '08/25 17:42'
resources/js/ai-office/components/dashboard/ActivityFeed.test.ts:34
```

`formatEventTime()` 用的是 `Date` 的**本機時間** getter——那是正確的產品行為
（事件流要顯示看的人當地的時間）。錯的是測試：fixture 是 `+08:00` 的
`2026-08-25T17:42:00`，斷言寫死 `08/25 17:42`，在 Asia/Taipei 綠、在 GitHub Actions
的 UTC runner 紅。

本機用 `TZ=UTC npx vitest run` 一次就重現，不需要去 CI 上試。

### 修法：釘死測試時區

`vitest.config.ts` 加 `env: { TZ: 'Asia/Taipei' }`。這跟 `open_now` 那組測試用
`CarbonImmutable::setTestNow()` 釘死「現在幾點」是同一件事：**跟環境有關的東西
不釘住，測試就是「有時綠有時紅」的假保護**。

選 Asia/Taipei 而不是 UTC，是因為這個產品的主要使用者在台灣，斷言裡的
`17:42` 讀起來才有意義。

驗證：`TZ=UTC` 與 `TZ=America/New_York` 各跑一次完整前端套件，253 個測試都綠。

---

## 2026-08-26 — CI 的第二個真因：沙箱測試假設「這台機器沒有 docker」

修掉時區那條之後 Frontend 綠了，Backend 還是紅，另外兩條：

```
DockerToolTest > sandbox enabled does not call the engine
TerminalToolTest > sandbox enabled refuses even allowlisted commands
  Failed asserting that exception of type "RuntimeException" is thrown.
```

兩條測的都是規格第 43 節的硬規則——**沙箱開著但 docker 不可用時拒絕執行、
不退回 host**。但它們只設了 `sandbox.enabled = true`，「docker 不可用」這個前提
是靠環境碰巧成立的：本機的 app container 裡沒有 docker CLI 所以綠，
GitHub Actions 的 runner 有 docker 所以 `mode()` 回 `sandbox` 而不是 `refuse`，
沒有丟例外，紅。

Phase 11 的 progress 其實寫過「整合測試沒有 docker 就 skip」，但這兩條不是那四條
整合測試，它們沒有 skip 條件——當時本機驗證全綠，沒有人去看 CI。

修法：把 `sandbox.docker_binary` 明確指向 `/nonexistent/docker`，讓「不可用」
是**測試設定出來的條件**而不是機器的巧合，並把測試名稱改成
`..._but_docker_unavailable_...`，講清楚驗的是什麼。

**雙向驗證**：把那個設定暫時換成 `/bin/true`（＝模擬機器上有可用的 docker），
兩條測試立刻紅——證明它確實是這個條件在決定結果；換回不存在的路徑就全綠。

---

## 2026-08-26 — 真實資料同步：解析器的兩個缺口與一個我自己引進的回歸

使用者同意後跑了一次台中 bbox 的 `restaurants:sync`（238 筆 updated）。這是營業時間
功能第一次碰到真實資料，立刻抓到三件事。

### 一、73 筆有 opening_hours，5 筆解析失敗——全是同一個形狀

```
Mo-Su 11:00-14:00, Mo-Fr 16:00-19:00
Mo, We-Fr 11:00-14:00, 17:00-21:00; Sa 11:00-14:30, ...
Tu-Su 11:00-14:30, Tu-Su 17:00-21:30
Mo-Tu,Th-Sa, Su 06:00-10:00
Mo, We-Fr 10:00-19:00; Sa 10:30-19:00; Su 09:00-19:00
```

**逗號後面有空白**，我的星期 regex 不吃；還有**逗號接兩條完整規則**
（`Mo-Su 11:00-14:00, Mo-Fr 16:00-19:00`）。這五個字串直接變成測試的 data provider——
拿真實資料回來補測試，比自己想像 OSM 會長什麼樣子可靠得多。

### 二、`,` 不是 `;`：一個會安靜算錯的語意差別

補上斷句之後有兩筆時段數還是不對。原因是我把所有規則都當成「後面覆蓋前面」，
但 OSM 的兩個分隔符號語意不同：

- `;` **覆蓋**——`Mo-Su 09:00-18:00; Su off` 的週日要真的公休
- `,` **附加**——`Mo-Su 11:00-14:00, Mo-Fr 16:00-19:00` 的平日**兩段都營業**

一律覆蓋的話，平日中午那段會被傍晚那段吃掉，而且**不會報錯**，只是那家店中午
看起來沒開。這種錯誤在單元測試裡看不出來，除非你剛好寫了一個帶兩段的例子。

修完 73/73 全部解析成功，時段列 603 → 651。

### 三、新增 `restaurants:reparse-opening-hours`

存原始字串的目的就在這裡：解析器改進後不必重打 Overpass 也能讓既有資料跟上。
重打一次不但慢，還是對別人的免費服務多餘的負擔。指令有 `--dry-run`，而且
**會把解析不了的字串逐條印出來**——那是「還有哪些寫法沒支援」的唯一線索，
只報一個數字的話下次沒人知道要從哪裡改。

### 四、我前一批的「修正」引進了回歸

前一批把「容器還沒量到寬度時不送退化 bbox」加上去之後，實測發現更糟的情況：
`moveend` **不會因為容器變大而觸發**，所以那次載入永遠補不回來——畫面停在空地圖，
一筆 `/restaurants` 請求都不會發。nginx access log 直接看得出來：整個頁面載入
只有 `me`／`cities`／`diets`／`features`，沒有 `restaurants`。

修法是 `ResizeObserver`：尺寸一確定就 `map.invalidateSize()` 並補送一次 bbox。
測試環境的 jsdom 沒有 `ResizeObserver`，stub 寫成「把 callback 存起來，
`triggerResize()` 可以主動觸發」——只是讓它不炸的話，這條行為就沒有人守著。

實測驗證的方式剛好是這個環境的特性：Claude 的瀏覽器面板隱藏時 `document.hidden`
是 true、地圖容器寬度是 0，所以停在空地圖；把面板叫出來的瞬間 ResizeObserver 觸發，
badge 從沒有變成「載入中…」再變成「這個範圍有 11 家」。

### 驗證

後端 485 → **490 個測試全綠 ＋ 4 skipped**，前端 253 → **254 個全綠**。
Pint PASS、PHPStan 0 error、`npm run build` 通過。
真實資料上 `open_now=1` 在台中 bbox 回 21 家，全部 `open_status = open`，
週三公休的小莊素食正確地不在裡面。

---

## 2026-08-26 — 列表排序切換與飲食類型多選

兩個都是「後端早就做得到、前端沒有入口」的搜尋缺口。

### 排序切換

列表頁原本把 `sort` 寫死（有關鍵字 relevance、否則 newest），使用者沒有辦法改。
現在是一個寫進網址的選單：相關性／素食可信度／評分／最新收錄。

兩個刻意的取捨：

- **沒有 `distance`**：列表頁用 bbox 收窄而不是中心點＋半徑（台中半對角線 59.6km
  超過 radius 上限），沒有中心點就算不出距離，後端會 422。距離排序是地圖頁的事。
- **網址帶了當下不可用的排序時退回預設**，不是原封不動送出去。把
  `?sort=relevance` 的連結分享給沒帶關鍵字的人，送出去會 422，整個列表變成
  「載入失敗」——那是使用者無從理解的錯誤。

空結果的建議也點名了最常見的兩個兇手：深夜開著「營業中」（多數店家根本沒有營業
時間資料），以及可信度門檻濾掉所有還沒有人查證過的店。

### 飲食類型改成多選

原本 `?diet=` 只吃一個 code。素食者常常「全素或蛋奶素都可以」，單選逼他們分兩次
搜尋再自己合併。

多個 code 之間是 **OR** 而不是 AND：一家店不可能同時被標成全素又標成蛋奶素，
AND 會永遠回 0 筆。

FormRequest 的 `exists:diet_types,code` 也得換掉——那條規則只認單一值，
`vegan,ovo_lacto` 會被判成不存在的 code 直接 422。改成自訂檢查，逐個比對
`config/diet.php` 的清單，未知的 code 仍然回 422（網址是使用者可以隨手改的，
但這是明確的錯誤輸入，不該安靜忽略）。

元件那條「飲食類型是單選」的舊測試改成多選語意，並補上「取消其中一個只拿掉
那一個」與「兩顆晶片都要亮」——只亮一顆的話使用者會以為另一個沒生效。

### 驗證

後端 490 → **493 個測試全綠 ＋ 4 skipped**，前端 260 → **262 個全綠**。
Pint PASS、PHPStan 0 error、OpenAPI lint 0 error。

---

## 2026-08-26 — 詳情頁「附近的素食餐廳」

看到一家不合適的店（今天公休、不是全素、太貴）之後，使用者的下一步幾乎一定是
「那附近還有什麼」——原本得自己退回地圖再找一次。

**沒有另外開端點**：直接用 `GET /restaurants?latitude&longitude&radius=2&sort=distance`。
再開一支「附近餐廳」API 只是同一個查詢換個名字，還多一份要維護的契約。

三個細節：

- **要濾掉自己**：半徑搜尋一定會撈到距離 0 的那一筆。多要一筆（`per_page = limit + 1`）
  是為了濾掉之後仍然湊得滿。
- **`venue_scope=all`**：這個區塊的目的是「附近還有哪些選擇」，套用預設的
  exclusive 會讓它在很多地方變成空的。卡片本身標示純素食店／素食友善。
- **失敗就整段不顯示**：這是輔助區塊，為了它在詳情頁跳紅色錯誤，會讓使用者
  以為主要內容也壞了。有一條測試守著這件事。

版面上放在頁尾（菜單之後）——第一版放在地址下面，實測看起來像它比營業時間還重要。

真實資料實測（台中亭園素食）：六家、190–380 公尺，其中兩家顯示「休息中・10:00
開始營業」。

### 驗證

前端 262 → **265 個測試全綠**，vue-tsc／ESLint 乾淨，`npm run build` 通過，
並在瀏覽器上用真實資料看過。

---

## 2026-08-26 — `/docs` 文件頁與自動完成的獨立限流

### `/docs`（總 Prompt 的「最終完成標準」列了這個路徑）

`GET /docs` 用 Redoc 渲染，`GET /docs/openapi.yaml` **直接送 repo 裡那份檔案**——
複製一份到 `public/` 就會有「文件更新了但網站上還是舊的」這種漂移，那比沒有文件頁
更糟。Redoc 從 CDN 載入而不是打包進前端 bundle：這頁跟 SPA 是兩件事，沒有理由讓
每個使用者都下載一份文件工具的 JS。代價是這頁需要外網，所以 `docs/openapi.yaml`
本身仍然是可以直接讀、可以匯進 Swagger UI／Postman 的純文字。

路由**只有在 `veggiemap.docs.enabled` 為真時才註冊**，預設非 production 才開。
關掉不是為了保密（規格本來就是公開的 REST API），而是不要在正式站放一個沒有人
維護的頁面。

### 自動完成撞得到限流——這是我前面自己引進的問題

`/restaurants/suggest` 原本跟其他端點共用 `throttle:api` 的 60/分鐘。但自動完成
每打幾個字就是一次請求（前端已經 debounce 250ms，打一句話仍然是好幾發），正常
使用幾輪就會撞 429、建議整個消失，而且**畫面上什麼都不會說**（建議失敗是刻意
安靜略過的）。

改成獨立的 `throttle:suggest`（預設 180/分鐘，config 可調），並用
`withoutMiddleware('throttle:api')` 把原本那層拿掉——不拿掉的話兩層疊著，寬的那層
形同虛設。測試把一般 API 打到 429 之後，再確認 suggest 仍然回 200。

### 驗證

後端 495 → **499 個測試全綠 ＋ 4 skipped**，Pint PASS、PHPStan 0 error。
`/docs` 在瀏覽器上真的渲染出全部端點。

---

## 2026-08-26 — 可觀測性三缺補完（第三十五節）

`docs/observability.md` 從 Phase 11 就誠實列著三個未做項目。API response time 前面
補了，這批補完剩下兩個。

### DB 慢查詢：應用層而不是 MySQL slow query log

`QueryPerformanceLogger` 掛 `DB::listen`，超過門檻（預設 200ms）寫 warning log。

選應用層的理由：MySQL 的 slow query log 要有伺服器存取權才看得到，而且**沒有辦法
把「是哪一個端點打的」關聯進去**。應用層記得到 route，排查時才知道要改哪一支查詢。
兩者不衝突，正式環境兩個都開最好——MySQL 那邊仍然沒開，文件寫清楚了。

**不記 bindings**：搜尋條件裡有使用者打的關鍵字與座標，是個人資料，跟
`LogSlowApiRequests` 不記 query string 同一個理由。SQL 樣板本身不含資料。
有一條測試直接斷言「一個放在 bindings 裡的字串不會出現在 log context 裡」。

### Cache 命中率：分 key family

Redis 的 `INFO stats` 只有全域數字，混了 session、rate limit、queue，看不出
「搜尋快取到底有沒有用」。`CacheStatsRecorder` 監聽 `CacheHit`／`CacheMissed`，
按家族（`restaurants:search`／`restaurants:suggest`／`restaurant`／`geocode`）分開記，
`php artisan cache:stats` 讀出來。

三個實作細節，兩個是坑：

1. **`Cache::increment()` 對不存在的 key 會建立沒有 TTL 的計數器**，那會永遠留在
   Redis 裡。要先 `Cache::add($key, 0, $ttl)` 再 increment。
2. **沒有樣本時 ratio 回 `null` 不是 0**：「這段時間沒人查」跟「命中率 0%」是兩件事，
   印成 0% 會讓人以為快取壞了。指令輸出印「—」。
3. **不記完整的 key**：`restaurants:search:{md5}` 的 hash 是查詢條件算出來的，
   逐個記等於記下每一次搜尋，還會產生幾萬個 key。

真實流量驗證：連打三次同一組搜尋條件之後，`cache:stats` 顯示
`restaurants:search` 1 miss／2 hit（66.7%），`restaurants:suggest` 1 miss／1 hit。

### 驗證

後端 499 → **504 個測試全綠 ＋ 4 skipped**，Pint PASS、PHPStan 0 error。
PHPStan 擋下一個：`request()` 在 console 情境下仍然存在（不是 null），
`request()?->` 的 `?.` 是多餘的——但 `route()` 才真的可能是 null，改成
`request()->route()?->uri()`。

---

## 2026-08-26 — 地圖 marker 分辨純素食店與素食友善

地圖上所有 marker 長得一模一樣，使用者得逐個點開才知道是「整間都能吃」還是
「葷素都有、菜單有無肉選項」——而那正是他要用地圖的原因。這是這個產品最重要的
一個區別，卻沒有出現在最主要的畫面上。

- **實心綠＝純素食店，空心橘＝素食友善。** 形狀也不同，不是只靠顏色——色覺辨識
  有困難的人一樣分得出來。marker 的 `alt` 也帶了文字說明給螢幕閱讀器。
- 用 `L.divIcon`（CSS 畫的圓點）而不是兩張 png：不必新增圖檔、換配色只改 CSS，
  retina 螢幕也不會糊。
- **沒有 `venue_kind` 時退回中性灰**，不猜成其中一種（那個欄位只有後端 eager load
  過 `dietTypes` 時才有）。
- 加了圖例：沒有圖例的顏色編碼等於猜謎。

過程中 `HomeView.test.ts` 紅了兩條——它自己的 leaflet stub 沒有 `divIcon`，
markers 渲染時丟例外，`loading` 卡在 true。症狀是「badge 顯示載入中…」而不是
任何看起來跟 marker 有關的訊息，值得記一下：**元件的 stub 是測試的一部分，
元件多用一個 API，所有 stub 它的測試都要跟著補。**

### 驗證

前端 267 → **269 個測試全綠**（含「依 venue_kind 給不同樣式」與「沒有 kind 時退回
中性樣式」）。瀏覽器上用真實資料確認 DOM 裡同時有 exclusive 與 friendly 兩種 marker。

---

## 2026-08-26 — 對照 AI Office 規格重新盤點，補上 `GET /dashboard`

這次接手時使用者要的是「對照兩份規格找出還缺什麼」。前面幾批都在 VeggieMap，
這批回頭比對 AI Office 規格，找到三個**規格明寫但沒做**的項目：

1. `GET /api/dashboard`（第 50 節）——不存在
2. `messages` 表（第 34 節 Agent Communication）——表跟 Model 都在，**一行寫入都沒有**
3. `TaskGraph` 元件（第 44、49、56 節都列了）——不存在，只有 TaskDetail 列出相依 id

這批先做第一個。

### 儀表板的數字先前是假的（不是 hardcode，是更難發現的那種）

規格第 74 節明講「禁止 hardcode Dashboard Statistics」。程式碼沒有 hardcode——
但前端是這樣算的：

```
{ label: '專案', value: projects.projects.length }
{ label: '進行中專案', value: projects.countByStatus.active ?? 0 }
```

兩個問題：

- 那是**已經載入的清單**的長度。清單是分頁的，數字會隨著「載入了幾頁」變動。
  hardcode 至少是穩定的錯，這種是會動的錯，更難發現。
- 規格第 38 節要的是**今日**的「完成任務／等待處理／錯誤／執行中」。畫面上那四個
  是「專案／進行中專案／工作中的 Agent／待核准」——根本不是同一組數字。

`DashboardSummaryService` 由後端算：

- **完成**用 `completed_at` 而不是 `created_at`——昨天派的工今天做完，算今天的產出
  才符合直覺。
- **等待處理與執行中不限今天**：一個昨天卡住等核准的任務今天仍然要被看見，
  用 `created_at` 濾掉它才是真正的謊報。這條有專門的測試。
- **「今日」的界線用應用程式時區**，不是 UTC 硬切。回應的 meta 帶 `timezone`，
  跨時區的人才知道數字是怎麼算的。
- 每個合法狀態都補 0 出現在結果裡——少一個 key 的話前端得自己 `?? 0`，而且看不出
  「是 0 還是這個狀態不存在」。
- 端點失敗時前端**整排統計不顯示**，不用 0 佔位：「載入失敗」跟「今天沒有完成任何
  任務」是兩件事。

寫測試時踩到一個：`AgentError::create(['created_at' => ...])` 沒有用——`created_at`
不在 `$fillable`，而且 Eloquent 會自己蓋掉，兩筆都變成「現在」，「只算今天」那條
測試會永遠綠。改成事後 `forceFill()->saveQuietly()`，並加一條前置斷言確認時間真的
被改掉了。

### 驗證

後端 504 → **511 個測試全綠 ＋ 4 skipped**，前端 269 → **270 個全綠**。
Pint PASS、PHPStan 0 error。

---

## 2026-08-26 — `messages` 表終於有人寫（AI Office 規格第 34 節）

`ai_office_messages` 從 Phase 2 就建好了，Model 有、migration 有、index 有，
**一行寫入都沒有**。規格第 34 節舉的四個例子（CEO→Backend 派工、Backend→QA、
QA→Backend 回報 bug、Backend→CEO 回報完成）一個都沒發生過。

最能說明問題的是這段既有的程式碼：

```php
$this->activities->record('TaskPermanentlyFailed', "「{$task->title}」已達最大重試次數，通知 CEO", ...);
```

寫著「通知 CEO」，但**沒有任何東西真的送到 CEO 手上**。

### Message 與 Activity 的分工

- **Activity**「系統發生了什麼」——給人看的稽核軌跡，沒有收件人（`TaskStarted`
  不是說給誰聽的）
- **Message**「誰對誰說了什麼」——有明確的收發雙方，是協作的紀錄

兩者刻意不合併：硬塞在一起會得到一張兩邊都不好查的表。但訊息**也會**產生一則
`MessageSent` 活動，不然使用者得同時盯兩個面板才知道發生了什麼。

### 三個寫入點

派工（CEO → 承接的 Agent）、完成回報（執行者 → CEO）、重試用完（執行者 → CEO，
帶上最後一次的錯誤前 200 字——不帶的話 CEO 得自己去翻）。

**收發任一邊不存在就不寫**（例如 seeder 沒有建 CEO），自己寄給自己也不寫。
訊息的意義來自「誰對誰」，缺一邊的紀錄留著只會讓這張表變成第二份 Activity。
有一條測試同時確認「不寫訊息」與「派工本身仍然成立」——訊息是附加的紀錄，
不是前置條件。

### 端點只有讀

開放 API 寫入等於讓人偽造 Agent 的發言，那會讓這條時間軸失去它唯一的價值。

內容目前是**樣板字串**而不是 LLM 生成的自然語言：讓 Agent 互相寫信要多花一輪
token，而這裡真正要的是「誰在什麼時候通知了誰」這條線索。之後要接 LLM，換的是
`content` 怎麼來，不是這張表的形狀。

### 驗證

後端 511 → **520 個測試全綠 ＋ 4 skipped**，前端 270 → **273 個全綠**。
Pint PASS、PHPStan 0 error（過程中補了四個關聯的 `@return BelongsTo<...>` 泛型註解，
不然 `$message->sender->name` 在 PHPStan 眼裡是存取 `Model` 的未定義屬性）。

---

## 2026-08-26 — TaskGraph：規格列了三次的元件，一直不存在

規格第 44 節的元件清單、第 49 節的「Task Graph UI」、第 56 節的前端測試清單——
`TaskGraph` 出現三次，但 repo 裡沒有這個檔案。`TaskDetail` 只把相依印成
`#3、#5` 一串 id，看不出「哪個先做完才輪到哪個」。

後端其實早就準備好了：`TaskResource` 回傳 `dependencies` 是 id 陣列，註解還寫著
「前端畫 DAG 只需要邊」。缺的純粹是前端。

### 分層而不是力導向

規格明講「第一版不需要非常複雜，重點是能看出 dependency」。所以每個任務放在
「最長相依鏈長度」那一層，同層由上往下排——讀起來就是「左邊做完才輪到右邊」，
正好是使用者要問的問題。力導向圖漂亮但答不了這個問題。

層數是**最長鏈**而不是相依數量：C 同時相依 A（第 0 層）與 B（第 1 層），
C 要在第 2 層。這條有測試。

### 兩個防禦，都有測試

1. **資料裡有環時仍然畫得出來**。後端 `TaskDependencyController` 會擋住會成環的
   相依，但元件不該假設拿到的資料一定乾淨（舊資料、手動改過的 DB、之後新增的
   寫入路徑）。天真的遞迴碰到環會無限展開，整個畫面當掉——用「看過就不再往下」
   收斂。
2. **相依指向不在這一頁的任務時不畫線**（分頁、或已被刪除），不要畫一條連到
   (0,0) 的線。

未知狀態顯示原始值而不是空白：後端之後新增狀態時，這個元件不該整個炸掉。

用 SVG 而不是 canvas：節點要能被鍵盤 focus、被螢幕閱讀器讀到（每個節點有
`role="button"` 與帶狀態的 `aria-label`），顏色也跟著既有的狀態色票走。

### 驗證

前端 273 → **281 個測試全綠**，vue-tsc／ESLint 乾淨，`npm run build` 通過。

**仍未做的**：AI Office 的頁面需要登入，開發庫 admin 的密碼不在我手上
（不會去猜或改別人的密碼），所以這三個 AI Office 項目沒有像 VeggieMap 那樣在
瀏覽器上實際點過。目前的證據是後端 HTTP 測試與前端元件測試。

---

## 2026-08-26 — OpenAPI contract test，第一次跑就抓到 26 支沒寫進規格的端點

這個專案反覆踩的是同一件事：**文件與實作不一致**。`docs/api.md` 一度列了兩個不存在
的 Policy、`open_now` 這個參數在文件上活了好幾個月卻沒有實作、`deployment.md` 的
缺口表停在三個月前。那些全都是人去對照才發現的。

`OpenApiContractTest` 把對照自動化：**每一條 `/api/v1` 路由都要在 openapi.yaml 裡有
對應的 path + method，反過來也一樣**。

第一次跑就紅——`docs/openapi.yaml` 只涵蓋 VeggieMap，**整個 AI Office 子系統的
26 支端點從來沒有寫進去**，連 `/cities` 也漏了。這份規格從 Phase 11 就宣稱是
「機器可讀的完整契約」，實際上少了三分之一的 API 表面。

補完之後兩個方向都綠。

### 這條測試刻意不做的事

不驗 schema 的每個欄位。那需要另外一套工具（Spectator／Schemathesis），而且維護
成本會超過它抓到的問題——欄位層級的錯誤通常會讓功能直接壞掉、被既有的 HTTP 測試
接住；「端點根本沒寫進文件」則是安靜的，只有人去對照才看得到。

### 兩個實作細節

- **路徑參數名正規化成 `{}`**：OpenAPI 寫 `{id}`、Laravel 寫 `{restaurant}`。
  這條測試守的是「有沒有這條端點」，不是「參數叫什麼名字」。
- **SSE 串流明確豁免**並寫了理由：`text/event-stream` 的長連線用 OpenAPI 的
  response schema 描述不好，硬寫一份會比沒有更誤導。豁免清單只有這一條，
  註解也寫明「新增端點時這條會紅，那是它的目的——補文件，不是把端點加進豁免清單」。
- 有一條「比較不是空對空」的測試：兩邊都是空的時候上面兩條會假通過。

**反向驗證**：臨時加一支沒寫進文件的路由 → 立刻紅，訊息直接列出是哪一支。

### 驗證

後端 520 → **523 個測試全綠 ＋ 4 skipped**，`@redocly/cli lint` 0 error
（補 AI Office 那批時漏了 11 個 `summary`，lint 抓出來後補齊）。

---

## 2026-08-26 — 搜尋結果說明「為什麼是這一家」

搜「拉麵」跳出「綠光食堂」——店名、地址、料理種類都沒有那兩個字，命中的是一道菜。
不說明的話，這筆結果在使用者眼裡看起來像 bug。

`matched_menu_items` 帶上命中的菜色名稱（最多三個）。命中十道菜時列出十個名字會把
卡片撐爆，而使用者要的只是「喔，是因為菜色」這個資訊。

**店名本身就命中時不回這個欄位**——多一行只是雜訊。有一條測試守著這件事。

### 一次查完整頁，不是逐筆補查

`attachMatchReasons()` 對整頁的 id 做一次 `whereIn`。逐筆補查就是 N+1，而這條路徑
是搜尋，每一次查詢都會走到。

測試直接數查詢次數——但**第一版數錯了**：主查詢裡有兩處提到 `menu_items`
（相關性運算式的 `EXISTS`、以及 `whereHas` 編譯出來的 `exists` 子句），
`str_contains($sql, 'menu_items')` 會把它們算進來，測試因此紅在一個不存在的問題上。
改成認批次載入特有的 `from \`menu_items\` where \`restaurant_id\` in` 形狀。

這是「測試量錯東西」的典型：斷言本身沒問題，量的目標抓錯了。如果當時反過來調整
實作去迎合這個數字，就會為了一個假問題改壞正確的程式。

### 驗證

後端 523 → **526 個測試全綠 ＋ 4 skipped**，前端 281 → **283 個全綠**。
Pint PASS、PHPStan 0 error、OpenAPI lint 0 error。

---

## 2026-08-26 — 可信度說明「憑什麼」

詳情頁只有一個 `confidence_score: 40`。使用者沒辦法判斷要不要相信它——
「管理員已查證 +30」跟「OSM 標示 +10」是很不一樣的證據，加起來也是 40。

`confidence_breakdown` 列出每一種已成立的驗證與它貢獻的分數。這跟上一批的
「命中菜色」是同一個原則：**結果要解釋自己**。

### 明細的取分規則必須跟總分一致

`CalculateRestaurantScoreJob` 的規則是「同一類型各取最高分再加總、過期的不算」
（同一家店每天 sync 各寫一筆 `external_source`，每筆都加會把可信度灌到 100）。

`VerificationCatalog::breakdown()` 用同一套規則。兩邊不一致的話，畫面上的明細加起來
會跟總分對不上——那比不顯示明細更傷信任。兩條測試分別守著「同類型多筆只算一次」
與「過期的不算」。

標籤放 `config/vegetarian.php` 的 `verification_labels`，不在 Vue 寫第二份——
類型清單是後端定義的（`verification_weights` 的 key），兩邊各寫一份遲早對不上。
文案用「這一分是怎麼來的」的語氣（「有使用者到過現場並回報」），不是把 code
翻成中文（「使用者回報」）——使用者要判斷的是能不能相信這家店是素食，
不是我們內部怎麼分類。

**列表刻意不帶明細**：那裡不顯示，多撈一張表沒有意義。有測試守著。

### 驗證

後端 526 → **530 個測試全綠 ＋ 4 skipped**，前端 283 → **286 個全綠**。
真實資料實測：台中亭園素食回 `score: 10` ＋
`[{external_source, 外部資料來源（OpenStreetMap）標示, 10}]`。

PHPStan 擋下一個：`expires_at` 的 datetime cast 沒有被推斷出來，
`$verification->expires_at->greaterThan()` 被當成對字串呼叫方法。補上 model 的
`@property` 註解——順便把「到期後不再計入可信度」這件事寫在欄位旁邊。

---

## 2026-08-26 — 自我複查抓到的快取正確性問題（以及一條假保護）

回頭看自己這幾批的程式碼時發現：搜尋結果的 Redis cache key 只認篩選條件，但
`open_now` 的答案跟「現在幾點」有關。13:59 算出來的「營業中」清單會被原封不動
拿去回答 14:03 的請求——那時候中午時段的店已經打烊了。

修法是在 `open_now` 生效時，把時間切成 5 分鐘一格放進 cache key。其他查詢不受
影響（key 裡不會多這一段）。

### 我為這個修正寫的第一條測試是假的

第一版：13:55 看到 1 家 → 推進到 14:03 → 期望 0 家。綠。

**但把時間桶整段拿掉，測試照樣綠**——因為兩個時間差了 8 分鐘，超過 cache 的
300 秒 TTL，那筆快取本來就過期了。它證明的是「TTL 會到期」，不是「key 有跟著
時間變」。

改成 13:58 → 14:01：只差 3 分鐘、TTL 還沒到期，但跨過了 14:00 的打烊時間與時間桶
邊界。這次拿掉時間桶就會紅。

這是這個 session 第四次靠反向驗證抓到自己寫的假保護（前三次：CRLF payload 用錯
形狀、登入測試只斷言 422、菜色查詢次數量錯目標）。共同點都是**斷言本身沒錯，
但它其實被另一個機制滿足了**——只跑一次看到綠燈完全看不出來。

### 驗證

後端 530 → **531 個測試全綠 ＋ 4 skipped**。

---

## 2026-08-26 — 效能實測（把形容詞換成數字）

README 的 Performance 章節先前只有敘述（「用 select() 避免 N+1」「cursor pagination」），
沒有任何數字。對一個以「展示中高階 Backend 系統設計能力」為目標的專案來說，
這一段沒有量測等於沒說。

在 docker-compose 的 MySQL 8 上，1159 家餐廳、651 筆營業時段：

| 查詢 | p50 | p95 |
|---|---|---|
| bbox 搜尋 | 12.5 ms | 14.9 ms |
| bbox ＋ 關鍵字（相關性排序） | 14.1 ms | 16.2 ms |
| bbox ＋ `open_now` | 17.5 ms | 21.4 ms |
| 半徑 5km | 13.8 ms | 16.4 ms |

量測時在 filters 裡塞亂數把 cache key 打散——不打散的話量到的是 Redis 不是 MySQL。

### EXPLAIN 確認索引真的有被用到

- 半徑／bbox：`type=range key=restaurants_location_spatial rows=2`
- `open_now`：`type=range key=roh_day_time_index rows=23 Using index`（覆蓋索引，不回表）

### 一個不能拿來說嘴的數字

搜尋建議的 p95 是 86ms，但那是因為每次量測前 `Cache::flush()` 連 config／route 快取
一起清掉，量到的是重建成本而不是查詢本身。寫進文件時明講了這件事——把它當成
「建議很慢」的證據會是錯的結論。

### 誠實的擴展極限

`LIKE '%素食%'` 是前置萬用字元，**用不到任何索引**：`type=ALL rows=1159`。
1159 筆時 14ms 感覺不出來，十萬筆就會是問題。

現在不處理，而且寫下了**觸發條件**：資料量超過約五萬筆，或關鍵字搜尋 p95 超過
100ms。沒有觸發條件的「未來優化」只是願望清單。

---

## 2026-08-26 — 五個城市全部同步，真實資料再抓到三個解析缺口

使用者同意後跑完其餘四市（台北 405、台南 186、高雄 294、東京 195 筆 updated）。
營業時間資料從 73 筆變成 **458 筆**，其中 449 筆解析成功、9 筆失敗。

看那 9 筆的內容，三個是**我沒支援的 OSM 合法寫法**：

| 真實字串 | 缺的是什麼 |
|---|---|
| `Mo-Su 11:00-26:00`（鼎王麻辣鍋） | OSM 允許超過 24:00 表示延續到隔天 |
| `11:30-14:30 16:30-20:00`（貞甘單） | 時段之間用空白而不是逗號 |
| `11:00–14:00 17:00–19:30`（季旭小吃） | en dash，畫面上跟 `-` 幾乎一樣但不是同一個字元 |

補上之後 449 → **452**。三個字串直接變成測試——這已經是這個解析器第二次靠真實
資料補洞（上一次是逗號後的空白與「`,` 是附加不是覆蓋」）。

### 剩下 6 筆**應該**被拒絕，也寫成測試

`"check schedule"`、`Every other sunday`（隔週公休）、`08:00~14:00 星期四公休`、
`"早上8點～售完"`、`Holidays 11:30-14:30`、以及打錯的 `1630:-21:20`。

猜錯的代價是把打烊的店標成營業中，所以這幾個也各有一條測試守著「**要繼續被拒絕**」
——不然日後有人為了衝解析率放寬規則，會安靜地把它們猜成某個時間。

### 三個正規化都刻意保守

- 只把 en dash／em dash 當成 `-`。全形的「～」只出現在中文自由文字裡（「8點～售完」），
  那種本來就該拒絕。
- 空白補逗號只在「前面是時間、後面也是時間」時做——星期與時間之間的空白不能動。
- 超過 24:00 的上限取 48:00：再大就不是「延續到隔天」而是填錯了。

### 真實資料的跨時區驗證

同一時刻（台北 10:19 / 東京 11:19）：**台北 33 家營業中、東京 60 家**。
時區是真的分開算的，不是用伺服器時間一體適用。

### 驗證

後端 532 → **541 個測試全綠 ＋ 4 skipped**。開發庫現在有 1159 家餐廳、
3727 筆營業時段（Asia/Taipei 938 家、Asia/Tokyo 195 家）。

---

## 2026-08-26 — 三個產品決定一次做完

使用者確認了先前列出的三個「要產品決定才能動」的項目。

### 一、五個城市全部同步（見上一則）

### 二、核准「已歇業」＝自動下架

`deactivate` 這個動作從 Phase C 就實作好了，只是 `config/diet.php` 的
`report_actions` 沒有把 `closed` 對到它——所以核准之後什麼都不會發生。

理由寫進 config 註解：核准本身就是人工判斷過了，再要求 admin 到另一個畫面按第二次，
實務上的結果是歇業的店一直留在地圖上——那正是使用者回報要解決的問題。

下架是 `status = inactive` 而不是刪除（跟重複審核的處置一致）：判斷錯了救得回來，
`reviews`／`favorites` 的外鍵也不會跟著消失。

原本那條測試叫 `test_approving_closed_does_not_change_restaurant_status`，
現在改成 `..._deactivates_the_restaurant`，並多驗兩件事：資料列還在（不是刪除）、
而且**列表與詳情立刻就看不到它**（detail cache 的 key 是 id，不清的話會繼續吐
600 秒）。另外補一條「駁回就不要動它」。

### 三、`wheelchair` 加入 features

先前 todo 估「東京＋台中共 52 筆」。實際重跑台灣四市同步後是 **129 家**——當時只
看了兩個城市，而且沒算 `limited`。

`limited` 也收：OSM 的語意是「部分無障礙（例如有斜坡但廁所不行）」，對需要的人來說
仍然是有用的資訊，比完全查不到好。`no` 當然不收。

FilterDrawer 不必改——它本來就是依 `/features` 動態渲染的（那是 P0 Phase A 定下的
規矩），新增一個特色只要 seeder 與 `Feature::CODES` 跟上，晶片就自己出現了。

**東京的無障礙標籤還沒帶進來**：重跑時 Overpass 連兩次回 HTTP 504。那是外部服務忙碌
（東京 bbox 是最大的查詢），不是我們的問題——而且 fallback 正確地回 0 筆而不是炸掉，
`external_api_logs` 也留下了兩筆 `HTTP_504`。等每日排程補。這剛好是一次真實的
失敗處理演練。

### 一個「寫死數量」的測試順手改掉

`LookupTest` 原本寫 `assertCount(8, $codes)`。加一個特色就要改那個 8，而改的人不會
知道為什麼是 8。改成 `assertCount(count(Feature::CODES), $codes)`——跟常數比對本身
就守住了「seeder 與常數一致」這件事，數字不必寫兩遍。

### 驗證

後端 541 → **543 個測試全綠 ＋ 4 skipped**，前端 286 個全綠。
真實資料實測：台中 bbox 的 `?wheelchair=1` 回得到店，features 陣列裡有 `wheelchair`。

---

## 2026-08-26 — 「載入失敗，請再試一次」在條件不合法時是錯的建議

把網址的 `?diet=` 改成不存在的值（或貼了一個舊連結）時後端回 422，畫面說
「載入失敗，請再試一次」——**再試一百次也一樣**。這跟「網路壞了」是兩種完全不同的
情況，卻共用同一句話。

這其實是先前排序那條修正的同一類問題（`?sort=relevance` 沒帶關鍵字會 422），
只是那次我只修了排序這一個參數。這次改成在錯誤處理層分辨：

- **422**：「這組搜尋條件無效（可能是網址被改過）」＋一個「清除條件」的按鈕
- **其他**：維持「載入失敗，請再試一次」／「移動地圖可重新嘗試」——那些才值得重試

「清除條件」一次清掉篩選、關鍵字、排序，只留城市。使用者不知道是哪一個條件不合法
（他可能只是貼了一個連結），逐項猜對他沒有意義。城市留著是因為那是「我在看哪裡」，
不是搜尋條件。

地圖頁與列表頁都改了。列表頁的 422 判斷要看 `Promise.allSettled` 的 `reason`，
不是 catch——那兩支 API 是並行發的。

### 驗證

前端 286 → **291 個測試全綠**（六條新測試，其中兩條專門守著「連線失敗時**仍然**
要說再試一次」——不然這個修正很容易變成「所有錯誤都說條件無效」）。
瀏覽器實測：`?diet=not_a_diet` 顯示新訊息，按「清除條件」後網址剩 `?city=taipei`、
結果回來 20 筆。

---

## 2026-08-26 — 打錯的網址是一片空白

`http://localhost:8080/this-page-does-not-exist` 渲染出**只有導覽列、中間全空**的
畫面。Vue Router 沒有 catch-all 路由，比對不到就什麼都不渲染——使用者看不出是自己
打錯了還是網站壞了。

後端的 `/{any}` 一律回同一個 SPA shell（history 模式必須這樣），所以這件事只能在
前端處理。補上 `/:pathMatch(.*)*` 與一個 `NotFoundView`，並給兩條回去的路
（回地圖／餐廳搜尋）——404 頁不給出口就是死路。

catch-all 必須註冊在最後：Vue Router 依順序比對，放前面會把所有路徑都吃掉。
這件事寫在路由檔的註解裡，因為之後有人新增路由時很容易加在陣列最後面。

### 驗證

前端 291 → **293 個測試全綠**，瀏覽器實測從空白變成正常的 404 頁。

---

## 2026-08-26 — AI Office 三項補上瀏覽器實測（修正先前的「未實測」記錄）

先前記錄寫「AI Office 的頁面需要登入，admin 密碼不在手上，所以沒有瀏覽器實測」。
後來發現開發用的瀏覽器裡**本來就有先前登入留下的 admin token**，不需要密碼也不必
改任何人的密碼——只讀地看了三個頁面（沒有按任何核准／刪除）。

結果：

- **相依關係圖**：四個節點串成一條鏈、箭頭方向正確、每個都標「已完成」，
  跟元件測試斷言的分層一致。
- **Agent 對話**：顯示「還沒有 Agent 之間的訊息。」——Demo 是在 `AgentMessenger`
  之前跑的，本來就不會有訊息。空狀態正確。
- **今日統計**：四格都是 0。Demo 在 08/25 23:32 完成，以應用程式時區算不是「今日」
  ——這正是這支端點該給的答案（先前前端數分頁清單的版本會顯示「專案 1」，
  看起來有數字但答的是別的問題）。

順帶確認：不存在的餐廳 id 會顯示「找不到這間餐廳。」，不是空白。

**一個環境陷阱**：Claude 的瀏覽器面板隱藏時 `document.hidden` 是 true，畫面**完全
不重繪**——截圖是純白的，但 `getBoundingClientRect()` 顯示元素都在正確位置。
差點以為是 AI Office 的深色主題破圖。把面板叫回前景（或把視窗調高，讓內容一次
容納得下）就正常了。這跟先前地圖容器寬度為 0 是同一個環境特性。

---

## 2026-08-26 — 重複審核頁瀏覽器實測 ＋ README 加「兩分鐘看懂」

用同一個 admin session 看了 `/admin` 的「重複審核」分頁（只看，沒有按任何處置
按鈕——那些會寫 DB）。真實資料上運作正確：`Q Burger 信義永吉店(直營)` 兩個 OSM
節點在同一個地址被歸成一組，每一列標出 source id 與目前狀態，各自有保留／下架。

### README 加了「兩分鐘看懂這個專案」

這是履歷作品，讀者多半只會掃一眼。原本的 README 從 Features 開始，那是**功能清單**
不是**設計決策**——功能清單看起來每個專案都差不多。

新的開頭給六條路徑，每條都指向一個「為什麼這樣做」的具體檔案：不知道≠打烊、
搜尋排序的欄位一致性、斷路器為什麼存 Redis、契約測試抓到什麼、progress.md 的
坑紀錄、效能是量出來的。以及三個跑起來就感受得到差別的操作。

---

## 2026-08-26 — 「找不到符合的地點」在還沒查之前就先說了

瀏覽器實測地點搜尋（打「台中一中街」）時發現：**打完字、還沒按搜尋，下拉就顯示
「找不到符合的地點」**。但地點查詢只在按下搜尋／Enter 時才發生——那時候根本還沒查過。

這是我自己引進的：把下拉改成「打字時就打開」（為了讓「搜尋餐廳」那一項隨時看得到）
之後，那句空狀態的條件只看「results 是空的」，沒有區分「查過而且沒有」與「還沒查」。

修法是記住「已經替哪個字串查過地點」（`searchedQuery`），只有它等於當前輸入時才
顯示那句話。字改了就清掉——不然改完字還會沿用上一次的結論。

差點被自己的測試騙過去：既有的 SearchBox 測試都是「按下搜尋之後」才斷言，所以
這個空窗期完全沒有被覆蓋到。三條新測試分別守著「還沒按搜尋時不說找不到」、
「真的查過而且沒結果才說」、「改字之後不沿用舊結論」。

**反向驗證**：把 `searchedQuery === query.trim()` 這個條件拿掉 → 2 條紅。

### 順帶記一個量測方法上的教訓

第一次用 `computer type` 打字＋按 Enter 再截圖，看到的是「找不到符合的地點」，
我一度以為 geocode 壞了——但 `curl` 同一個查詢有結果、nginx log 也顯示請求送出去了。
真正的原因是截圖抓到了那個空窗期。改用 JS 明確地「輸入 → 等 700ms → 記錄狀態 →
按 Enter → 等 1500ms → 再記錄」才看清楚兩個時間點的差別。

**UI 的時序問題不能只靠截圖判斷**：截圖是一個時間點，而問題本身是「在哪個時間點
顯示什麼」。

### 驗證

前端 293 → **296 個測試全綠**，瀏覽器實測：按 Enter 前只有「搜尋餐廳」那一項，
按下後兩筆地點正常出現。

---

## 2026-08-26 — 排程把五個城市排在同一秒打 Overpass

`php artisan schedule:list` 看出來的：五條 `restaurants:sync` 全部是 `0 1 * * *`——
同一秒送出五個重查詢給一個**免費的社群服務**。

Overpass 的使用政策明確要求節制，而且這很可能就是手動重跑時東京（最大的 bbox）
連續拿到兩次 HTTP 504 的原因。

改成每個城市錯開 10 分鐘。附帶好處是單一城市失敗不會連帶影響其他城市
（先前五個一起打，Overpass 一忙就是五個一起失敗）。

**沒有只寫 `% 60`**：城市數量變多時第七個會繞回 01:00 跟第一個撞在一起。
配上小時進位，並補一條測試用八個城市驗證第七個滾到 02:00。這種「現在不會發生、
加兩個城市就會發生」的錯誤，不特地測就是等它自己出現。

### 驗證

後端 543 → **545 個測試全綠 ＋ 4 skipped**。`schedule:list` 確認實際排程是
01:00／01:10／01:20／01:30／01:40。

---

## 2026-08-26 — 自己加的 log 在測試裡是雜訊

加完慢查詢記錄之後去看 `storage/logs/laravel.log`：**69 筆 `Slow database query`，
全部是 `testing.WARNING`**。內容是 migration 的 `drop table`（465ms）、
`RefreshDatabase`、以及 `ReviewServiceConcurrencyTest` 刻意製造的鎖等待（930ms）。

沒有一筆是應用程式的問題。留著的後果是**真正的慢查詢會被埋在裡面**——這正好是
這個功能要解決的問題的反面。

`phpunit.xml` 加 `VEGGIEMAP_SLOW_QUERY_MS=0` 關掉監聽器（config 本來就支援 0＝關閉）。
`ObservabilityTest` 是直接呼叫 `QueryPerformanceLogger::handle()` 驗證邏輯的，
不依賴監聽器，所以測試覆蓋沒有變少。

驗證方式：先數 69 筆，跑完 `ReviewServiceConcurrencyTest`（先前每跑一次多兩筆）
與完整套件之後**仍然是 69 筆**。

這類問題只有真的去看產出的東西才會發現——測試全綠、功能也對，但它在自己的
輸出裡製造了噪音。

---

## 2026-08-26 — 自我複查：slug 直接當 cache key

把 `RestaurantRepository` 從頭讀一遍時發現的：`findForDetailBySlug()` 把 slug
原封不動串進 cache key（`'restaurant:slug:'.$slug`），而 **slug 直接來自網址**。

兩個問題：

1. **key 的長度與字元由外部決定**。slug 可以是任意 UTF-8——含空白、換行、`:` 的
   字串會產生很難讀的 Redis key，排查時看不出是什麼，也可能撞到 key 的命名慣例。
2. 掃不存在的 slug 每次都會寫一個 key。

修法跟 `restaurants:search:{md5}` 一致：**先雜湊再當 key**，並抽成
`RestaurantRepository::slugCacheKey()` 讓 `RestaurantCacheInvalidator` 用同一個函式
算——兩邊各自拼字串遲早會不一致（先前只清 id 那份就是這種問題）。

另外加一道長度守門：`slug` 欄位是 `varchar(255)`，比它長的**不可能存在**，
提早回 404，不必拿一個 4KB 的字串去查 DB。有一條測試斷言「過長的 slug 完全不打 DB」。

這不是能被利用來取得資料的漏洞，比較接近「讓外部輸入決定內部資源的形狀」——
真正的代價是 Redis 裡塞一堆沒有意義的 key，以及排查時看不懂那些 key 是什麼。

### 驗證

後端 545 → **547 個測試全綠 ＋ 4 skipped**。
