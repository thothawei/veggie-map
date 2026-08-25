# API Overview — VeggieMap

Phase 0 產出的端點清單與回應格式約定，這份文件是 Phase 3~5 實作時的對照表。完整、可餵給
Swagger UI／Postman 的 OpenAPI 3.0 規格見 [`docs/openapi.yaml`](openapi.yaml)（Phase 11 產出，
`npx @redocly/cli lint` 驗證過 0 error）。

## Base

所有 API 前綴 `/api/v1`。

## 回應格式

成功：

```json
{ "success": true, "data": {}, "meta": {} }
```

錯誤：

```json
{ "success": false, "error": { "code": "RESTAURANT_NOT_FOUND", "message": "Restaurant not found" } }
```

不使用 Laravel 預設 exception response 當正式回應——這代表需要一個全域 Exception Handler，把
`ModelNotFoundException`、`ValidationException`、`AuthorizationException` 等統一轉成上述格式，
在 Phase 3 建立第一支 Controller 時一併處理。

## 端點清單

| Method | Path | 說明 | 認證 |
|---|---|---|---|
| GET | `/restaurants` | 列表＋搜尋（見下方查詢參數） | 選用（登入可帶收藏狀態） |
| GET | `/restaurants/recommended` | 推薦餐廳（首頁用，見下方） | 無 |
| GET | `/restaurants/{id}` | 詳情 | 選用 |
| POST | `/restaurants/{id}/favorite` | 加入收藏 | 必須 |
| DELETE | `/restaurants/{id}/favorite` | 取消收藏 | 必須 |
| POST | `/restaurants/{id}/reviews` | 新增評論 | 必須 |
| GET | `/restaurants/{id}/reviews` | 列出評論 | 選用 |
| POST | `/restaurants/{id}/reports` | 回報 | 必須 |
| GET | `/diets` | 飲食類型清單 | 無 |
| GET | `/features` | 特色清單 | 無 |
| GET | `/cities` | 地圖可切換的城市清單（前端城市切換器用） | 無 |
| GET | `/geocode` | 地址/地標轉經緯度（搜尋框用，見下方） | 無 |
| GET | `/me` | 目前使用者 | 必須 |
| GET | `/me/favorites` | 我的收藏 | 必須 |
| POST | `/auth/register` | 註冊 | 無 |
| POST | `/auth/login` | 登入 | 無 |
| POST | `/auth/logout` | 登出 | 必須 |
| GET | `/admin/reports` | 待審核回報列表（Phase 7） | 必須（admin） |
| POST | `/admin/reports/{id}/approve` | 核准回報（Phase 7） | 必須（admin） |
| POST | `/admin/reports/{id}/reject` | 駁回回報（Phase 7） | 必須（admin） |
| GET | `/admin/reviews` | 評論列表，含 hidden（Phase 7） | 必須（admin） |
| POST | `/admin/reviews/{id}/hide` | 隱藏評論（Phase 7） | 必須（admin） |
| POST | `/admin/restaurants/{id}/menu-items` | 新增菜單（Phase C，diet_type 合法值見 `GET /diets` 的 `meta.menu_item_diets`） | 必須（admin） |
| GET | `/admin/verification-types` | 可手動寫入的驗證類型（code／label／分數，來自 `config/vegetarian.php`） | 必須（admin） |
| POST | `/admin/restaurants/{id}/verifications` | 手動記錄一筆驗證，寫完立刻重算素食可信度 | 必須（admin） |

## `GET /restaurants` 查詢參數

`keyword`, `latitude`, `longitude`, `radius`（公里，上限 50）, `bbox`, `city`, `district`, `diet`,
`venue_scope`（`exclusive`／`friendly`／`all`，名稱與合法值見 `GET /diets` 的 `meta.venue_scope`；
**省略＝不過濾**，前端預設會送 `exclusive`）, `price_level`,
`rating_min`, 以及 `features.code` 對應的布林篩選（`pet_friendly`／`parking`／`delivery`／`takeout`／`reservation`／`wifi`／`outdoor_seating`／`family_friendly`；請傳 `1`／`0`，也接受 `true`／`false` 字串）, `open_now`, `sort`（distance/rating/popular/newest，
預設 `distance`；帶 `latitude`+`longitude` 才可用 `distance`）, `page`, `per_page`（預設 20，上限 100）。

範例：

```
GET /api/v1/restaurants?latitude=24.1477&longitude=120.6736&radius=5&diet=vegan&takeout=1
GET /api/v1/restaurants?bbox=23.9500,120.4300,24.4500,121.4700&sort=newest
GET /api/v1/restaurants?bbox=35.5300,139.5600,35.8200,139.9200&venue_scope=exclusive
```

列表與詳情在 eager load 過 `dietTypes` 時會帶 `venue_kind`／`venue_badge`／`venue_summary`
（文案來自 `config/diet.php` 的 `copy`，不是前端寫死）。有任何 exclusive diet 就是
純素食店；否則有 friendly diet 就是素食友善。

`GET /diets` 每筆有 `kind`／`group_label`，`meta.venue_scope` 是篩選參數名、預設值與選項，
`meta.menu_item_diets` 是菜單層 `diet_type` 的 code／label（詳情頁分組與寫入驗證都讀這份，
不要在前端再寫一份 enum）。

詳情頁的 `menu_items` 每筆帶 `diet_label`。沒有菜單時帶 `menu_empty_message`（文案依
`venue_kind` 與 `source` 來自 `config/diet.php` copy），OSM 同步**不會編造**菜單。

Admin 核准 `not_vegetarian`／`menu_changed` 之後的連動見 `config/diet.php` 的
`report_actions`（exclusive 店降為 friendly、過期菜單清空）。沒列的 type（例如 `closed`）
仍只改回報狀態、不動餐廳。


### `bbox`：矩形範圍查詢

格式 `"minLat,minLng,maxLat,maxLng"`。前端城市切換用這個參數，**不是 `city` 欄位**——
匯入資料裡 59% 的 `city` 是空的、同一個城市有「臺中市／台中市」兩種寫法、東京的節點
填的是「渋谷区」這類行政區（2026-08-25 實測）。

也不能用 `latitude`+`radius` 代替：`radius` 上限 50km，而台中 bbox 的半對角線是 59.6km、
高雄 66.4km，換算後會直接被驗證擋下。

- 可與 `keyword`、`diet`、`parking` 等其他篩選併用。
- 同時帶 `latitude`+`longitude` 時，範圍仍由矩形決定（不會再套半徑把四角切掉），
  座標只用來算距離供 `sort=distance` 使用。
- 格式錯誤、角落顛倒、座標超出範圍都回 422，不會靜默忽略——靜默忽略會讓
  「查這座城市」變成「查全世界」。
- 邊界是**嚴格排除**的（底層是 MySQL `MBRContains`），剛好壓在邊界上的座標不會被收錄。

## `GET /geocode` — 地址搜尋（Phase 8.5）

首頁「搜尋地點」輸入框用：使用者打地名/地標，轉成經緯度後帶入 `GET /restaurants` 的
`latitude`/`longitude`/`radius`。透過 `GeocodingProviderInterface` → `NominatimGeocodingProvider`
呼叫 Nominatim（見 `docs/external-apis.md`），同一查詢字串 Redis cache 1 天（`geocode:{md5(q)}`）
避免超過 Nominatim 每秒 1 次請求的政策限制。

參數：`q`（必填，2~255 字）。

```
GET /api/v1/geocode?q=台中一中街
```

```json
{
  "success": true,
  "data": [
    { "display_name": "台中一中, 育才街, 新北里, 北區, 台中市, 40403, 台灣", "latitude": 24.1503069, "longitude": 120.6865624 }
  ]
}
```

Nominatim 逾時／失敗時回 `{"success": true, "data": []}`（不讓地圖首頁因為外部服務掛掉而整個壞掉），
失敗紀錄寫進 `external_api_logs`。

**踩過的坑**：`EXTERNAL_API_NOMINATIM_USER_AGENT` 如果帶 `example.com` 這種常見教學範例網域
（例如 `contact: you@example.com`），會被 Nominatim 的防護機制直接 403 擋掉——這不是程式碼邏輯錯誤，
是 User-Agent 字串本身被擋，真的打過 Nominatim 才發現，光看程式碼看不出來。已改成
`VeggieMap/1.0 (+https://github.com/thothawei/veggie-map)`。

## `GET /restaurants/recommended` — 推薦餐廳

首頁「推薦餐廳」用（見總體規劃第三十節）。候選集是同一套 search()（半徑或 bbox），
`RuleBasedRecommendationService`（`app/Services/Recommendation/`）依六個分量加權排序，
不是單純依 rating 排序：

```
score = distance_score * 0.25 + rating_score * 0.20 + vegetarian_confidence * 0.25
      + feature_match * 0.15 + popularity * 0.10 + freshness * 0.05
```

權重在 `config/recommendation.php`，不寫死在程式碼裡。`RecommendationServiceInterface`
是 Adapter Pattern（跟 `RestaurantProviderInterface`／`GeocodingProviderInterface`
同一套設計），未來要換 `AIRecommendationService` 只改 `AppServiceProvider` 的綁定，
`RestaurantController` 不用動。

參數：`latitude`／`longitude`（必填）、`radius`（公里，預設 5，上限 50）、`bbox`（與列表相同，
不受 50km 限制）、`limit`（預設 6，上限 20），
以及與列表相同的 `diet`、`venue_scope` 與特色布林篩選（`takeout`／`wifi`／`pet_friendly` 等）。首頁推薦會跟著
目前篩選收窄候選集，不是另外撈一組沒篩過的。

```
GET /api/v1/restaurants/recommended?latitude=25.033&longitude=121.5645&radius=5&limit=6
GET /api/v1/restaurants/recommended?latitude=24.1477&longitude=120.6736&takeout=1
GET /api/v1/restaurants/recommended?latitude=24.1477&longitude=120.6736&bbox=23.9500,120.4300,24.4500,121.4700
```

回應的每筆 Restaurant 多一個 `recommendation_score`（0~1），只有這支端點才會出現。

## Pagination

列表 API 採 Cursor Pagination（依 `id` 遞增）而非 offset，避免大資料集下 `OFFSET N` 效能劣化
（見 `docs/architecture.md`）。回應 `meta` 需帶 `next_cursor`。

## Validation / Authorization

每個會寫入的端點對應一個 FormRequest（`SearchRestaurantRequest`、`CreateReviewRequest`、
`CreateRestaurantReportRequest`…）與一個 Policy（`ReviewPolicy`、`RestaurantPolicy`、`ReportPolicy`、
`FavoritePolicy`）。Controller 只做「呼叫 Service／回傳 Resource」，不做欄位驗證與授權判斷。

## Caching（`RestaurantRepository`）

- `GET /restaurants`：`restaurants:search:{md5(filters)}`，`Cache::tags(['restaurants'])`，
  TTL 300s。不同篩選條件／排序／cursor 各自獨立的 cache entry。
- `GET /restaurants/{id}`：`restaurant:{id}`，TTL 600s。route 不用 implicit model
  binding（那樣會在進 controller 前就先查一次 DB，等於白做快取），改用純 id 查詢，
  查詢本身包在 `Cache::remember()` 裡。
- 寫入後清快取：`Restaurant`／`RestaurantConfidenceScore` 兩個 model 都掛了 Observer，
  存檔／刪除時 `Cache::forget("restaurant:{id}")` + `Cache::tags(['restaurants'])->flush()`，
  不做全域 `Cache::flush()`（會清掉跟餐廳無關的 cache，例如 geocode 結果）。

## Rate Limiting

整個 `/api/v1/*` 套 `throttle:api`，60 次／分鐘，依登入使用者 id 或 IP 分桶
（`AppServiceProvider::boot()` 的 `RateLimiter::for('api', ...)`）。底層走
`CACHE_STORE=redis`，是 Redis-based limiter，不需要額外套件。超過限制回 429，
回應帶 `X-RateLimit-Limit`／`X-RateLimit-Remaining` header。

## AI Office 子系統（`/api/v1/ai-office/*`）

多 Agent 開發平台的端點。整體規劃見 [implementation-plan.md](implementation-plan.md)。

**所有端點都需要 `auth:sanctum` + AI Office 角色**（`admin` / `manager` / `developer` /
`viewer`）。餐廳地圖的一般消費者角色 `user` 一律 403——這是路由層的 `ai-office` 中介層
預設拒絕，不是各端點各自檢查。

| Method | Path | 說明 | 可寫入的角色 |
|---|---|---|---|
| GET | `/ai-office/health` | readiness：DB／Redis／佇列／workspace 真實連線檢查 | 唯讀 |
| GET | `/ai-office/projects` | 專案列表（`?status=`、`?per_page=`） | 唯讀 |
| POST | `/ai-office/projects` | 建立專案（同步只建檔，`PlanProjectJob` 進佇列規劃） | admin, manager, developer |
| GET | `/ai-office/projects/{id}` | 專案詳情（含 `task_count`） | 唯讀 |
| PUT/PATCH | `/ai-office/projects/{id}` | 更新專案 | admin, manager, developer |
| DELETE | `/ai-office/projects/{id}` | 刪除專案（連帶刪除底下任務） | admin, manager |
| GET | `/ai-office/projects/{id}/tasks` | 任務列表（`?status=`、`?assigned_agent_id=`），依 priority 由大到小 | 唯讀 |
| POST | `/ai-office/projects/{id}/tasks` | 建立任務，可帶 `dependencies: [taskId,...]` | admin, manager, developer |
| GET | `/ai-office/tasks/{id}` | 任務詳情，含 `dependencies_satisfied` 布林值 | 唯讀 |
| PATCH | `/ai-office/tasks/{id}` | 更新任務（狀態、優先度、指派 Agent） | admin, manager, developer |
| POST | `/ai-office/tasks/{id}/dependencies` | 新增相依，body `{ "depends_on_task_ids": [1,2] }` | admin, manager, developer |
| DELETE | `/ai-office/tasks/{id}/dependencies/{dep}` | 移除相依 | admin, manager, developer |
| GET | `/ai-office/agents` | Agent 列表（`?role=`、`?status=`），不含 system prompt | 唯讀 |
| GET | `/ai-office/agents/{id}` | Agent 詳情，含 system prompt、工具清單、權限表、目前任務數 | 唯讀 |

### 健康檢查

`GET /ai-office/health` 每一項都是真的去連線，不是讀設定檔回報。任一項失敗回 **503** 且
`data.status = "degraded"`。回應永遠不含 API key，只回 `llm.api_key_configured` 布林值。

```json
{
  "success": true,
  "data": {
    "status": "ok",
    "checks": {
      "database": { "ok": true, "latency_ms": 1.2, "driver": "mysql", "database": "veggiemap" },
      "redis":    { "ok": true, "latency_ms": 0.8, "client": "phpredis" },
      "queue":    { "ok": true, "latency_ms": 0.5, "connection": "redis", "queue": "ai-office", "pending_jobs": 0 },
      "workspace":{ "ok": true, "latency_ms": 0.1, "path": "/var/www/html/workspace" }
    },
    "llm": { "provider": "mock", "api_key_configured": false },
    "limits": { "max_agent_steps": 25, "max_tool_calls": 50, "max_retries": 3, "max_token_budget": 200000 },
    "sandbox_enabled": true
  }
}
```

### 循環相依

`POST /ai-office/tasks/{id}/dependencies` 會在寫入前做 DFS 偵測，擋掉自環、直接互指與
間接繞回（A→B→C→A）。命中時回 **422**：

```json
{
  "success": false,
  "error": {
    "code": "VALIDATION_ERROR",
    "message": "...",
    "fields": { "depends_on_task_ids": ["這組相依會造成循環相依，整條任務鏈會永遠等不到前置完成。"] }
  }
}
```

擋在寫入前的理由：成環之後不會有任何錯誤訊息，那條鏈上的每個任務都在等前面的完成、
永遠等不到，只是安靜地不動——跑起來才發現的成本遠高於建立時就拒絕。

菱形相依（B、C 都依賴 A，D 依賴 B 與 C）是合法 DAG，不會被擋。

### 規劃與派工（Queue）

`POST /ai-office/projects` **不會**在 HTTP request 裡呼叫 LLM。回應 201 時專案已建立、
`status` 仍是 `planning`；規劃由 `PlanProjectJob` 在 `ai-office` 佇列執行（Horizon
`supervisor-ai-office`）。測試環境 `QUEUE_CONNECTION=sync` 會立刻跑完，CRUD 測試必須
`Queue::fake()`，否則沒有 Agent 時規劃會把專案標成 failed。

規劃產出必須是通過 `PlanSchema` 驗證的 JSON。角色白名單來自
`config/ai_office.php` 的 `planner.assignable_roles`，不是寫死的 `backend`／`frontend`。
抽不出 `{...}` 的自然語言清單會被拒絕，CEO 最多重試 `planner.max_attempts` 次後專案
`failed`。

指派只看 role、idle 優先、最低 workload；並行上限在 **dispatch** 時檢查
（`running` 數 vs `max_concurrency`），沒人可跑時任務仍會留下 `assigned_agent_id`。
人手 `POST .../tasks` 或 `PATCH /tasks/{id}` 補上 Agent 且前置齊了，才 `tryDispatch`。

失敗重試走 `RetryFailedTaskJob`（延遲 `jobs.retry_delay_seconds`），不跟 Laravel job
`tries` 疊加。達 `max_retries` 後寫活動 `TaskPermanentlyFailed`，專案若沒有仍在進行的
任務則標 `failed`。

### 任務相依是否滿足

`dependencies_satisfied` 只在所有前置任務都是 `completed` 或 `approved` 時為 `true`。
`failed` / `cancelled` 的前置**不算滿足**——否則前面壞掉的整條鏈會繼續往下跑。
