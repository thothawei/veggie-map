# Observability — VeggieMap

Phase 11 產出。這份文件記錄「目前實際有什麼」跟「刻意還沒做什麼」，不是規劃書——已實作的部分
都對照到實際的檔案/表格，避免寫成看起來完整但其實沒接的清單。

## Laravel Logs

`LOG_CHANNEL=stack` → `LOG_STACK=single` → `storage/logs/laravel.log`（`config/logging.php`
預設值，沒有客製化）。`LOG_LEVEL=debug`（`.env.example`），local/開發環境用；production 部署時
應該調成 `warning` 以上，避免 log 檔案被 debug 等級的雜訊灌爆——這是部署前要記得改的設定，
目前程式碼沒有依 `APP_ENV` 自動切換。

例外處理集中在 [`App\Exceptions\ApiExceptionRenderer`](../app/Exceptions/ApiExceptionRenderer.php)：
所有 `/api/*` 的未捕捉例外先轉成統一格式回應給使用者，同時仍然會走 Laravel 預設的例外記錄
（`report()` 沒有被覆寫，未捕捉例外一樣會進 `storage/logs/laravel.log`）。

## API Response Time

**沒有**針對一般 API 端點（`/restaurants`、`/auth/*` 等）記錄逐次請求的 response time——
Laravel 沒有內建這個功能，這個專案也沒有另外接 middleware 或 APM 工具去做。唯一有記錄
response time 的是外部 API 呼叫（見下方 External API Failures），不要誤會成「全站請求
都有 response time 追蹤」。

如果之後要加，合理的做法是一個輕量 middleware（`microtime()` 前後差）寫進獨立的
`api_request_logs` 表或轉發到 APM（例如 Laravel Pulse／New Relic），不是本次 Phase 範圍。

## Queue Failures

`failed_jobs` 表存在（Laravel 11 預設骨架的 `0001_01_01_000002_create_jobs_table.php` 一併
建立），`QUEUE_CONNECTION=redis`（`.env.example`）。

**但目前這張表實務上不會有資料**——`CalculateRestaurantScoreJob`／`RecalculateRestaurantRatingJob`
雖然都實作 `ShouldQueue`，呼叫端全部用 `dispatchSync()` 同步執行（見
[docs/progress.md](progress.md) Phase 6 的決定：這個專案還沒有跑 queue worker，真的
`dispatch()` 進 Redis 佇列會因為沒有 worker 消化而卡住）。同步執行的例外會直接冒泡到
呼叫端（例如 `ReviewService::submit()` 內），不會進 `failed_jobs`。等 Horizon／queue worker
真的架起來、呼叫端改回 `dispatch()` 之後，`failed_jobs` 才會是有意義的失敗記錄來源
（見 [docs/todo.md](todo.md) 的已知技術債）。

## External API Failures

[`external_api_logs`](../database/migrations) 表，`App\Models\ExternalApiLog`。目前兩個
外部 Provider 都會寫：

- [`OsmRestaurantProvider`](../app/Services/External/OsmRestaurantProvider.php)（Overpass API，
  `restaurants:sync` 批次匯入用）
- [`NominatimGeocodingProvider`](../app/Services/External/NominatimGeocodingProvider.php)
  （`GET /geocode` 地址搜尋用）

每筆記錄：`provider`、`endpoint`、`status`（HTTP status code）、`response_time_ms`、
`success`（boolean）、`error_code`（HTTP 狀態碼字串或例外類別名稱，見
[docs/progress.md](progress.md) Phase 8.5 的 `retry()` 踩坑記錄）、`created_at`。

**絕對不記錄 API Key／Token**——Overpass 沒有 key，Nominatim 也不需要 key（只需要
`User-Agent`），這兩個 Provider 本來就沒有東西可以誤記；如果之後接需要 key 的付費
Provider，寫入 `ExternalApiLog` 前要記得排除 key 欄位，不能照抄現有的 log 格式直接塞
整包 request payload。

查詢範例（找出最近失敗率高的 provider）：

```sql
SELECT provider, COUNT(*) AS total, SUM(!success) AS failures
FROM external_api_logs
WHERE created_at > NOW() - INTERVAL 1 DAY
GROUP BY provider;
```

## Cache Hit / Miss

Cache 本身已經實作：`GET /geocode`（`GeocodeController`）、`GET /restaurants`／
`GET /restaurants/{id}`（`RestaurantRepository`，見 [docs/api.md](api.md) 的
Caching 段落）都走 Redis，寫入後由 `RestaurantObserver`／
`RestaurantConfidenceScoreObserver` 主動清快取。

**但命中率追蹤沒有實作。** Redis 本身可以用 `INFO stats` 看全域的
`keyspace_hits`／`keyspace_misses`，應用層沒有針對個別 cache key（例如區分
`restaurant:{id}` 命中率 vs `restaurants:search:*` 命中率）另外記錄。這是誠實的缺口——
目前的驗證方式是測試裡直接數 DB query 次數（見 `RestaurantCachingTest`），不是量測
production 流量下的命中率，兩者是不同層級的保證。

## Database Slow Query Awareness

**沒有實作。** 沒有掛 `DB::listen()` 記錄慢查詢，也沒有開 MySQL 的 slow query log。
[`docs/database.md`](database.md) 記錄了 Index 設計的理由，`RestaurantRepository::search()`
的兩段式查詢（Bounding Box 過濾 → `ST_Distance_Sphere` 精算）是唯一經過 `EXPLAIN` 手動驗證過
的查詢（見 [docs/progress.md](progress.md) Phase 3），但那是開發時的一次性驗證，不是持續監控。

## Health Check

Laravel 11 內建的 `/up` 路由（`bootstrap/app.php` 的 `health: '/up'`），回 200 代表應用程式
能正常處理請求（不含資料庫連線檢查）。`tests/Feature/ExampleTest.php` 原本測 `GET /` 是否
200，Phase 12 接 CI 時才發現 `/` 現在是需要 Vite manifest 的 SPA shell——這個健康檢查用途
更適合 `/up`（不需要任何前端資產），但目前測試套件還沒有實際切過去驗證這一條路徑
（見 [docs/todo.md](todo.md)）。

## 現況總結：有 vs 沒有

| 項目 | 狀態 |
|---|---|
| 例外統一格式化＋記錄到 `storage/logs/laravel.log` | ✅ 已實作 |
| 外部 API 呼叫記錄（provider/status/response_time/success） | ✅ 已實作，`external_api_logs` |
| Queue 失敗記錄（`failed_jobs`） | ⚠️ 表存在，但目前 `dispatchSync` 讓它實務上不會有資料 |
| 一般 API 端點的 response time 追蹤 | ❌ 未實作 |
| Cache hit/miss 追蹤 | ❌ 未實作 |
| DB 慢查詢記錄 | ❌ 未實作，僅 Phase 3 手動 `EXPLAIN` 過一次 |
| Health check endpoint | ✅ Laravel 內建 `/up` |
