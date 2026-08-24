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

## `GET /restaurants` 查詢參數

`keyword`, `latitude`, `longitude`, `radius`（公里）, `city`, `district`, `diet`, `price_level`,
`rating_min`, `pet_friendly`, `parking`, `open_now`, `sort`（distance/rating/popular/newest，
預設 `distance`；帶 `latitude`+`longitude` 才可用 `distance`）, `page`, `per_page`（預設 20，上限 100）。

範例：

```
GET /api/v1/restaurants?latitude=24.1477&longitude=120.6736&radius=5&diet=vegan&pet_friendly=1
```

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

首頁「推薦餐廳」用（見總體規劃第三十節）。候選集是同一套半徑搜尋結果，
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

參數：`latitude`／`longitude`（必填）、`radius`（公里，預設 5）、`limit`（預設 6，上限 20）。

```
GET /api/v1/restaurants/recommended?latitude=25.033&longitude=121.5645&radius=5&limit=6
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
