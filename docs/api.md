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
