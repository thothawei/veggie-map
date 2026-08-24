# TODO — 剩餘規劃

> 用途：Claude Code 下一次接手時的進度追蹤清單。完成一項就打勾＋commit push（見
> [progress.md](progress.md) 的 commit/push 慣例），不要一次全做。

## Phase 8.5 — 地址搜尋（Geocoding，優先）✅ 已完成 2026-08-24

回應「輸入提示字找到素食地圖」需求：目前 `GET /api/v1/restaurants` 只吃數字座標，
使用者無法打地名。`docs/architecture.md` 已設計好介面，只是沒實作。

- [x] `GeocodingProviderInterface`（`app/Services/External/`）
- [x] `NominatimGeocodingProvider`：呼叫 Nominatim `/search`，5s timeout、429 退避重試、
      寫 `ExternalApiLog`，帶合法 `User-Agent`（見 [external-apis.md:23](external-apis.md)）
- [x] `GET /api/v1/geocode?q=關鍵字` 端點：回傳候選地點清單（含 lat/lng）
- [x] Redis cache：`geocode:{md5(q)}`，TTL 1 天（同查詢字串避免撞 Nominatim rate limit）
- [x] Feature test（mock HTTP，不真打 Nominatim API）
- [x] 更新 `docs/api.md` 補上這條端點
- [x] （額外抓到的坑）`.env` 的 `EXTERNAL_API_NOMINATIM_USER_AGENT` 預設值帶 `example.com`
      會被 Nominatim 403 擋掉，已改成真實 repo URL，見 progress.md／api.md 細節

## Phase 9 — Vue 3 + Leaflet 前端 ✅ 已完成 2026-08-24

- [x] Vite + Vue 3 + TypeScript + Pinia + Vue Router 專案初始化（沿用 Laravel 既有
      `laravel-vite-plugin` 整合，SPA 由 `resources/views/app.blade.php` 承載，不是獨立
      跑在 5173 的專案，見 progress.md 的架構決定）
- [x] 頁面骨架：`/`、`/restaurants`、`/restaurants/:id`（後端是 id-based route model
      binding，不是 slug）、`/login`、`/register`、`/favorites`、`/profile`、`/admin`
- [x] 地圖元件：Leaflet + `leaflet.markercluster`，依 map bounds 查詢（不一次載全部）、
      目前位置、marker popup——瀏覽器實測過 cluster 展開、marker 點擊跳轉
- [x] 首頁搜尋框：輸入文字 → call `/geocode` → 取得座標 → 帶入 `/restaurants` 半徑搜尋
      → 移動地圖視角——瀏覽器實測過真的打 Nominatim 並飛到選取地點
- [x] Filter drawer：素食類型／寵物友善／停車，串 `/diets`、`/features`
- [x] Axios + Pinia：auth token 存取、攔截器帶 Bearer token、favorites 狀態——瀏覽器實測過
      註冊／收藏／評論／評分即時更新的完整流程
- [ ] Mobile responsive：只用桌面尺寸驗證過，還沒切手機視窗檢查版面
- [ ] `AdminView` 的核准/駁回/隱藏三個操作只走過程式碼審視，沒有真的拿 admin 帳號在
      瀏覽器裡點過（需要先手動改 DB 把某帳號設 `role=admin`）

## Phase 10 — 補測試缺口

- [ ] Unit test：`RestaurantRepository::boundingBoxPolygon()`（純計算邏輯）
- [ ] Unit test：距離計算相關純邏輯
- [ ] `ReviewService` 併發競態測試（目前只驗證循序覆蓋，沒測真並行）
- [ ] `veggiemap_testing` 建庫流程腳本化（目前手動下 SQL，CI 環境跑不起來）
- [ ] 前端 Vitest／Playwright（目前 Phase 9 全靠手動瀏覽器驗證，見 progress.md）
- [ ] Phase 9 遺留的兩項：mobile responsive、Admin 頁面瀏覽器實測

## Phase 11 — 文件收尾

- [x] README 正式改寫（Phase 8.5 一併完成，涵蓋四十節要求的所有章節）
- [ ] `docs/openapi.yaml`（含 Phase 8.5 新增的 `/geocode`）
- [ ] `docs/observability.md`

## Phase 12 — GitHub Actions CI

- [ ] `.github/workflows/ci.yml`：Install → Pint → PHPStan → 自動建 `veggiemap_testing`
      → PHPUnit → build
- [ ] 前端存在後補：ESLint／TypeScript／Vitest／build

## Phase 13 — 部署文件

- [ ] 只產出 deployment documentation，不執行 production 部署（未確認 AWS credentials 前）

## 已知技術債（progress.md 記錄，一併排入）

- [ ] `users:promote {email}` Artisan 指令（目前只能手動改 DB 升 admin）
- [ ] 安裝 Laravel Horizon，把 `dispatchSync()` 改回 `dispatch()`（目前沒有 queue worker，
      所有 Job 用 `dispatchSync` 頂著，見 [progress.md](progress.md) Phase 6 決定）
- [ ] `routes/console.php` 排程：定期跑 `restaurants:sync`、
      `restaurants:recalculate-ratings`、`restaurants:calculate-scores`（目前只能手動執行）
