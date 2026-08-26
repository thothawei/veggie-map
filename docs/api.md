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
| GET | `/restaurants/{idOrSlug}` | 詳情 | 選用 |
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
| GET | `/ai-office/projects/{id}/messages` | Agent 之間的往來訊息（規格 §34），唯讀 | 必須（AI Office 角色） |
| GET | `/ai-office/dashboard` | AI Office 今日統計（完成／等待／錯誤／執行中）＋各狀態分佈 | 必須（AI Office 角色） |
| GET | `/admin/duplicates` | 被標為 `is_possible_duplicate` 的餐廳，依「同名＋100m 內」分組（`stale=true` 代表同組另一筆已處理、標記過期） | 必須（admin） |
| POST | `/admin/restaurants/{id}/duplicate` | 處置一筆：`action=keep`（清標記）或 `action=deactivate`（下架，不刪除）。**沒有 merge／delete** | 必須（admin） |
| GET | `/admin/verification-types` | 可手動寫入的驗證類型（code／label／分數，來自 `config/vegetarian.php`） | 必須（admin） |
| POST | `/admin/restaurants/{id}/verifications` | 手動記錄一筆驗證，寫完立刻重算素食可信度 | 必須（admin） |

## API 文件頁

`GET /docs`（Redoc）與 `GET /docs/openapi.yaml`。規格檔直接送 repo 裡的
`docs/openapi.yaml` 本人，不複製到 `public/`——複製就會有「文件更新了但網站上還是
舊的」這種漂移。

預設只在非 production 註冊路由（`VEGGIEMAP_DOCS_ENABLED`）。關掉不是為了保密
（規格本來就是公開的 REST API），而是不要在正式站放一個沒有人維護的頁面。

## `GET /restaurants/suggest` 搜尋建議（自動完成）

`?q=關鍵字&city=台中市`（`city` 選填）。回三種型別的建議，每類最多 5 筆：

```json
{
  "success": true,
  "data": {
    "restaurants": [{ "id": 7, "name": "十方齋", "city": "台中市", "district": "西區" }],
    "cuisines": [{ "code": "japanese", "label": "日式料理" }],
    "districts": [{ "city": "台中市", "district": "西區" }]
  }
}
```

- **店名**依相關性排序（同 `sort=relevance` 的權重），只回 `active` 的店。
- **料理種類**比對 `config/cuisine.php` 的中文標籤與 code，且**只回實際上有餐廳
  掛著的分類**——建議一個點下去 0 筆的分類等於騙使用者。
- **行政區**直接查 `restaurants` 的既有值，不是寫死清單：涵蓋範圍由匯入資料決定。
- Redis cache 60s（`restaurants:suggest:{hash}`，tag `restaurants`）。自動完成是
  逐字打出來的，同一個前綴會被反覆查詢，正是 cache 最有效的形狀。
- **限流另計**（`throttle:suggest`，預設 180/分鐘）：自動完成每打幾個字就是一次請求，
  跟其他端點共用 60/分鐘的話，正常打字幾輪就會撞 429、建議整個消失。

## `GET /restaurants` 查詢參數

`keyword`, `latitude`, `longitude`, `radius`（公里，上限 50）, `bbox`, `city`, `district`, `diet`,
`diet`（可逗號分隔多個，例如 `diet=vegan,ovo_lacto`，彼此是 **OR**）,
`venue_scope`（`exclusive`／`friendly`／`all`，名稱與合法值見 `GET /diets` 的 `meta.venue_scope`；
**省略＝不過濾**，前端預設會送 `exclusive`）, `price_level`,
`rating_min`, `confidence_min`（素食可信度下限 0–100，門檻選項見 `GET /diets` 的
`meta.confidence_filters`）, 以及 `features.code` 對應的布林篩選（`pet_friendly`／`parking`／`delivery`／`takeout`／`reservation`／`wifi`／`outdoor_seating`／`family_friendly`；請傳 `1`／`0`，也接受 `true`／`false` 字串）, `open_now`（只留此刻在該店**當地時間**營業中的餐廳；沒有可解析營業時間的店不會出現在結果裡）, `sort`（distance/rating/popular/newest，
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

### 關鍵字搜尋

`keyword` 比對的欄位：**店名、地址、城市、行政區、描述、菜色名稱、料理種類標籤**。
之所以不只比店名——素食使用者常用的搜尋詞是「拉麵」「滷味」「泰式」，那些是菜色與
料理種類。

- **多詞是 AND**：`keyword=台中 拉麵` 兩個條件都要命中；空白、`,`、`，`、`、` 都可分隔。
- **長度門檻**（`config/veggiemap.php`）只用來砍掉多詞查詢裡的雜訊詞；全部被砍光時
  退回用原字串當單一詞，不會變成「回傳全部餐廳」。
- **`%`／`_` 會被跳脫**，`keyword=100%` 不會退化成萬用字元查詢。
- **`sort=relevance`**：店名完全相同 > 店名開頭 > 店名包含 > 菜色 > 料理種類 >
  地區 > 描述（權重見 `App\Repositories\Search\KeywordSearch`）。同分時依距離、
  再依 id，避免翻頁時同一家店重複出現或消失。
- 帶 `keyword` 時**預設就是 `relevance`**；沒帶時維持原本的 `distance`／`newest`。
  `sort=relevance` 沒帶 `keyword` 會回 422，不會悄悄退回其他排序。

### 附近的餐廳（前端組合，沒有新端點）

詳情頁的「附近的素食餐廳」直接用 `GET /restaurants?latitude&longitude&radius=2&sort=distance&venue_scope=all`，
沒有另外開一支推薦端點——那會是同一個查詢換個名字。前端自己把「自己」濾掉
（半徑搜尋一定會撈到距離 0 的那一筆）。

### 素食可信度篩選與排序

`confidence_min=N` 只留下可信度 ≥ N 的餐廳；`sort=confidence` 依可信度由高到低排序
（沒有分數列的餐廳算 0 分排在最後，不是被濾掉）。列表回應現在也帶 `confidence_score`，
不必點進詳情才看得到。

門檻選項（值與標籤）來自 `config/vegetarian.php` 的 `confidence_filters`，經
`GET /diets` 的 `meta.confidence_filters` 給前端。前端不決定「幾分算有查證」——那是
產品判斷，會跟著 `verification_weights` 一起調整。

### 營業時間

列表與詳情都會帶 `open_status`（`open`／`closed`／`unknown` 三態）、`open_now`
（unknown 時是 `null`）、`closes_at`／`next_opens_at`，詳情另外帶 `opening_hours_week`
（一週時間表，`ranges` 空陣列＝當天公休）與 `opening_hours_raw`（OSM 原始字串）。

`unknown` 是刻意保留的第三態：OSM 多數餐廳沒有 `opening_hours` 標籤，把未知壓成
「已打烊」會誤導使用者。同理，`open_now=1` **不會**把未知的店算進來。

解析只支援 OSM `opening_hours` 的常見子集（`24/7`、`Mo-Fr 11:00-14:00,17:00-21:00`、
逗號後有空白的 `Mo, We-Fr ...`、逗號接兩條規則的 `Mo-Su 11:00-14:00, Mo-Fr 16:00-19:00`、
跨午夜、`PH off`）；月份區間、週序、日出日落等寫法一律視為無法解析，見
`app/Support/OpeningHours.php`。

**`;` 與 `,` 語意不同**：`;` 是「後面覆蓋前面」（`Mo-Su 09:00-18:00; Su off` 的週日
真的公休），`,` 是「再加一條」（上面那個例子平日中午與傍晚兩段都營業）。

解析器改版後可以用 `php artisan restaurants:reparse-opening-hours` 拿**已存下來的原始
字串**重新產生時段列，不必重打 Overpass（`--dry-run` 先看會變成什麼）。

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

每個會寫入的端點對應一個 FormRequest（`SearchRestaurantRequest`、`SuggestRestaurantRequest`、
`CreateReviewRequest`、`CreateRestaurantReportRequest`、`ResolveDuplicateRestaurantRequest`…）。
Controller 只做「呼叫 Service／回傳 Resource」，不做欄位驗證與授權判斷。

實際存在的 Policy 是這五個（`app/Policies/`）：

| Policy | 管什麼 |
|---|---|
| `ReviewPolicy` | 評論的建立與 admin 隱藏 |
| `RestaurantReportPolicy` | 回報的建立與 admin 審核 |
| `RestaurantVerificationPolicy` | admin 手動寫入驗證 |
| `MenuItemPolicy` | admin 新增菜單 |
| `RestaurantPolicy` | admin 審核重複標記 |

收藏刻意沒有 Policy（只判斷是否登入，沒有「別人的收藏」這種概念）。
這段先前列了 `FavoritePolicy`／`ReportPolicy` 兩個不存在的名字，已更正。

所有 email 欄位另外掛 `App\Rules\SafeEmail`：Laravel 11 預設的 `email` 規則會放行
`"user\r\n"@example.com` 這種帶引號 local part 的 CRLF 值（CVE-2026-48019，
修補版本是 12.61.1+，屬 major upgrade）。這是緩解不是根治，見 [deployment.md](deployment.md)。

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
| GET | `/ai-office/approvals` | 核准列表（預設 `status=pending`；`?status=all` 全看；可 `risk_level`、`project_id`） | 唯讀 |
| GET | `/ai-office/approvals/{id}` | 單筆核准（含 payload、過期時間） | 唯讀 |
| POST | `/ai-office/approvals/{id}/approve` | 核准；可選 `comment`。HTTP 內不跑工具，丟 `ProcessApprovalJob` | admin, manager |
| POST | `/ai-office/approvals/{id}/reject` | 拒絕；工具不執行，任務標 `rejected` | admin, manager |
| GET | `/ai-office/projects/{id}/activities` | 事件流列表（`?after_id=`、`?type=`、`?task_id=`、`?per_page=`） | 唯讀 |
| GET | `/ai-office/agents/{id}/memories` | 這個 Agent 記得的事（`?project_id=`、`?memory_type=`） | 唯讀 |
| GET | `/ai-office/usage` | 用量與成本報表（`?project_id=`、`?agent_id=`、`?from=`、`?to=`） | 唯讀 |
| GET | `/ai-office/stats/agents` | 每位 Agent 的效能統計（`?project_id=`） | 唯讀 |
| POST | `/ai-office/projects/{id}/events/ticket` | 換一張開 SSE 用的一次性票 | 唯讀 |
| GET | `/ai-office/projects/{id}/events` | SSE 事件串流（`?ticket=`、`?after_id=`） | 憑票，票綁使用者與專案 |

### 事件流與 SSE（規格 §35／§36）

所有 Agent 動作、任務與 Agent 的狀態變動都寫進 `ai_office_activities`，前端有兩條讀法：

**補漏用的列表** `GET /ai-office/projects/{id}/activities`

- 不帶 `after_id`：由新到舊，看最近發生什麼。
- 帶 `after_id`：只回比它新的事件，且改成由舊到新——這是斷線重連後補齊的順序。
- `meta.latest_id` 是這個專案目前最大的事件 id，拿它當串流起點就不會重收歷史。

**即時推送** `GET /ai-office/projects/{id}/events`

瀏覽器的 `EventSource` 不能帶 `Authorization` 標頭，所以先用 Bearer token 換票：

```
POST /ai-office/projects/12/events/ticket
→ { "success": true, "data": { "ticket": "…", "expires_in": 60, "latest_id": 348 } }

new EventSource('/api/v1/ai-office/projects/12/events?ticket=…&after_id=348')
```

票只能用一次、預設 60 秒過期、綁定發票時的使用者與專案；不把 Sanctum token 放進網址，
因為網址會進 access log 與瀏覽器歷史。無效或過期的票回 **401**。

串流會送三種事件：

| event | 內容 | 用途 |
| --- | --- | --- |
| `activity` | 一筆事件的完整 JSON，`id:` 是事件 id | 主要資料 |
| `heartbeat` | `{ "last_id": 348 }` | 沒有新事件時也要有動靜，順便回報游標 |
| `reconnect` | `{ "last_id": 348 }` | 連線壽命到期，帶著 `last_id` 重連即可 |

上限全部在 `config/ai_office.php` 的 `events`：單一連線預設活 60 秒、同一使用者最多
3 條（超過回 **429** `TOO_MANY_CONNECTIONS`，不排隊）、每輪最多送 100 筆。這些是為了
避免長連線佔滿 PHP-FPM worker。瀏覽器自動重連時帶的 `Last-Event-ID` 標頭優先於
`after_id`，重連不會漏事件。

### 用量、成本與 Agent 效能（規格 §38／§40）

`GET /ai-office/usage` 全部從 `ai_office_token_usages` 聚合，沒有任何寫死的數字：

```json
{
  "success": true,
  "data": {
    "totals": { "requests": 12, "input_tokens": 15000, "output_tokens": 4000,
                "total_tokens": 19000, "estimated_cost": "0.123400" },
    "by_model":   [{ "model": "claude-opus-5", "requests": 8, "total_tokens": 15000, "estimated_cost": "0.120000" }],
    "by_agent":   [{ "agent_id": 7, "agent_name": "後端小周", "requests": 8, "total_tokens": 15000, "estimated_cost": "0.120000" }],
    "by_project": [{ "project_id": 1, "project_name": "待辦 API", "requests": 8, "total_tokens": 15000, "estimated_cost": "0.120000" }],
    "daily":      [{ "day": "2026-08-25", "total_tokens": 15000, "estimated_cost": "0.120000" }]
  },
  "meta": { "filters": { "project_id": null }, "pricing": { "claude-opus-5": { "input": 5, "output": 25 } } }
}
```

- **金額一律是字串、固定 6 位小數**，帳務數字不經過浮點數。
- 成本是加總寫入當下的 `estimated_cost`，**不在報表這層用現在的價目表重算**——重算的話，
  改了 `config/ai_office.php` 的價格連歷史帳都會跟著變。`meta.pricing` 回傳目前的價目表，
  讓畫面能說明「這些數字是用哪一份表估的」。價目表沒有的模型估成 0，不憑空補單價。
- `daily` 只回有用量的日子；把空白日補 0 是畫圖那一端的事。
- `to` 早於 `from` 回 **422**。

`GET /ai-office/stats/agents` 回每位 Agent 的 `tasks／completed／failed／retries／runs／
success_rate／avg_duration_ms／total_tokens／estimated_cost`。兩個地方刻意回 `null` 而不是 0：
沒接過任務的人沒有成功率、沒有成功執行過的人沒有平均耗時——0 和「還沒有資料」不是同一件事。
平均耗時只算 `status=completed` 的執行，否則「失敗得很快」會被算成效率高。

### Agent 記憶（規格 §41）

Agent 每完成或失敗一個任務就寫一則記憶（`task_result` / `error_pattern`），下次執行時
重要度最高的前幾則會被放進 prompt。`GET /ai-office/agents/{id}/memories` 的排序跟實際
recall 一致，所以清單前 `meta.recall_limit` 則就是下次真的會被送出去的那幾則。

`project_id` 為 null 的記憶是跨專案通則（例如使用者偏好），在任何專案下都會被想起來。
上限在 `config/ai_office.php` 的 `memory`：單則長度（超過截斷）與每次取幾則——記憶會進
context，等於每次請求都要為它付 token。

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

### 工具與安全邊界

Agent 執行迴圈裡的工具呼叫先過 `PermissionGate`（預設拒絕），再進工具本體：

- **FileTool**：讀寫／列出／搜尋都關在該專案 `workspace/{workspace_path}/`。`..`、
  `/etc/passwd`、symlink 逃逸、讀別的專案，一律拒絕。
- **GitTool**：cwd 是該專案 workspace，不是本 repo。`git_push` 碰到
  `tools.git.protected_branches`（預設 `main`／`master`）直接拒絕。
- **TerminalTool**：指令必須在 `tools.terminal.allowlist`；denylist 即使被加進
  allowlist 也硬擋。`SANDBOX_ENABLED=true` 時拒絕執行，不退回 host。
- **DockerTool**：名稱必須符合 `tools.docker.name_pattern`（含專案 id）；參數含
  `docker.sock`／`--privileged` 等片段直接拒絕。沙箱未就緒同樣不呼叫引擎。
- **DatabaseTool**：只允許 `allowed_prefixes`（SELECT／EXPLAIN／DESCRIBE）；
  `allowed_environments` 預設不含 production。

風險等級、白名單、禁止關鍵字都在 `config/ai_office.php` 的 `tools`，不寫死在工具類別裡。

### 人工核准

判定順序：Agent 權限 deny → 立刻拒絕；權限 approval **或** 風險達到
`AI_OFFICE_APPROVAL_THRESHOLD`（預設 `high`，含以上）→ 寫 `ai_office_approvals` 並暫停任務。
`critical` 一定要核准，threshold 設 `off` 也改不掉。權限表的 `allow` 代表「可以提出請求」，
不是「跳過人工」——devops 的 `git_push=allow` 在預設門檻下仍會進核准佇列。

`POST .../approve` 與 `POST .../reject` 只改狀態；真正執行工具的是 `ProcessApprovalJob`。
過期（預設 24 小時）後再按核准會 422。developer／viewer 看得到列表，按下去是 403。

### 任務相依是否滿足

`dependencies_satisfied` 只在所有前置任務都是 `completed` 或 `approved` 時為 `true`。
`failed` / `cancelled` 的前置**不算滿足**——否則前面壞掉的整條鏈會繼續往下跑。
