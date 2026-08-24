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
- [x] Mobile responsive（Phase 10 補測時抓到真的橫向溢出 bug 並修掉，見 progress.md）
- [x] `AdminView` 拿真的 admin 帳號在瀏覽器裡點過核准/駁回/隱藏（Phase 10 一併驗證，
      過程中還發現並修掉 router guard 的競態 bug：硬導航到 `/admin` 會被誤導回首頁）

## Phase 10 — 補測試缺口 ✅ 已完成 2026-08-24

- [x] Unit test：`RestaurantRepository::boundingBoxPolygon()`（純計算邏輯，含反向驗證：
      故意改錯數學公式，測試真的會紅）
- [x] ~~Unit test：距離計算相關純邏輯~~ 查證後發現不存在——距離計算 100% 在 SQL 端
      （`ST_Distance_Sphere`），沒有獨立 PHP 函式可以抽出來測，唯一的純 PHP 幾何邏輯
      就是上面的 bounding box，已經測了
- [x] `ReviewService` 併發競態測試：真的用背景 process + 原生 PDO 撐住 DB 鎖，讓兩個交易
      真的重疊，不是循序模擬。**過程中抓到一個真的 bug**：兩個交易對同一個空 index range
      的 gap lock 互相 INSERT 會被 InnoDB 判定 deadlock 直接丟例外，不是優雅序列化；
      `DB::transaction()` 原本沒有重試次數，已修成 `DB::transaction($fn, 3)`。細節與這個
      測試「能穩定驗證什麼、不能穩定驗證什麼」的誠實記錄見 progress.md。
- [x] `veggiemap_testing` 建庫流程腳本化：`scripts/setup-test-db.sh`（隨時可重跑）+
      `docker/mysql/init/01-create-test-database.sql`（全新 volume 自動跑）
- [x] 前端 Vitest：抽出 `resources/js/lib/geo.ts`（Haversine 距離計算）並測試——
      目前唯一的前端自動化測試，元件測試／Playwright E2E 仍未做，見下方未完成項目
- 未完成：前端元件測試／Playwright E2E（golden path 仍靠手動瀏覽器驗證，未列為本階段
  必須項目——這個專案規模，投入 Playwright 的 ROI 目前低於其他 Phase）

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
