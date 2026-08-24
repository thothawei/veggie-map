# TODO — 剩餘規劃

> 用途：Claude Code 下一次接手時的進度追蹤清單。完成一項就打勾＋commit push（見
> [progress.md](progress.md) 的 commit/push 慣例），不要一次全做。

## Phase 8.5 — 地址搜尋（Geocoding，優先）

回應「輸入提示字找到素食地圖」需求：目前 `GET /api/v1/restaurants` 只吃數字座標，
使用者無法打地名。`docs/architecture.md` 已設計好介面，只是沒實作。

- [ ] `GeocodingProviderInterface`（`app/Services/External/`）
- [ ] `NominatimGeocodingProvider`：呼叫 Nominatim `/search`，5s timeout、429 退避重試、
      寫 `ExternalApiLog`，帶合法 `User-Agent`（見 [external-apis.md:23](external-apis.md)）
- [ ] `GET /api/v1/geocode?q=關鍵字` 端點：回傳候選地點清單（含 lat/lng）
- [ ] Redis cache：`geocode:{md5(q)}`，TTL 1 天（同查詢字串避免撞 Nominatim rate limit）
- [ ] Feature test（mock HTTP，不真打 Nominatim API）
- [ ] 更新 `docs/api.md` 補上這條端點

## Phase 9 — Vue 3 + Leaflet 前端

目前完全還沒動。

- [ ] Vite + Vue 3 + TypeScript + Pinia + Vue Router 專案初始化
- [ ] 頁面骨架：`/`、`/restaurants`、`/restaurants/{slug}`、`/login`、`/register`、
      `/favorites`、`/profile`、`/admin`
- [ ] 地圖元件：Leaflet + marker clustering，依 map bounds 查詢（不一次載全部）、
      目前位置、marker popup
- [ ] 首頁搜尋框：輸入文字 → call `/geocode` → 取得座標 → 帶入 `/restaurants` 半徑搜尋
      → 移動地圖視角（依賴 Phase 8.5）
- [ ] Filter drawer：素食類型／寵物友善／停車／評分，串 `/diets`、`/features`
- [ ] Axios + Pinia：auth token 存取、攔截器帶 Bearer token、favorites 狀態
- [ ] Mobile responsive

## Phase 10 — 補測試缺口

- [ ] Unit test：`RestaurantRepository::boundingBoxPolygon()`（純計算邏輯）
- [ ] Unit test：距離計算相關純邏輯
- [ ] `ReviewService` 併發競態測試（目前只驗證循序覆蓋，沒測真並行）
- [ ] `veggiemap_testing` 建庫流程腳本化（目前手動下 SQL，CI 環境跑不起來）

## Phase 11 — 文件收尾

- [ ] README 正式改寫：Features／Architecture／Tech Stack／Database Design／
      API Documentation／Local Development／Docker／Testing／External APIs／
      Caching Strategy／Queue Architecture／Geospatial Search／Security／
      Performance／CI/CD／Future Roadmap
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
