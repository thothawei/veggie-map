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

## Phase 11 — 文件收尾 ✅ 已完成 2026-08-24

- [x] README 正式改寫（Phase 8.5 一併完成，涵蓋四十節要求的所有章節）
- [x] `docs/openapi.yaml`：全部 20 支端點（含 Phase 7 admin、Phase 8.5 `/geocode`），
      `npx @redocly/cli lint` 驗證過 0 error（先抓到並修正：`nullable` 需要搭配真實
      `type`、`components.info.license` 需要 url 或乾脆不寫——這個專案沒有 LICENSE 檔，
      不編一個假的 MIT 出來）
- [x] `docs/observability.md`：誠實記錄「有 vs 沒有」——`external_api_logs` 跟例外統一
      格式化是真的有做，API response time／cache hit-miss／DB 慢查詢追蹤都老實寫成未實作，
      `failed_jobs` 表存在但目前 `dispatchSync` 讓它實務上不會有資料，不是隨便寫一份
      看起來很完整但沒對照到程式碼現況的文件
- [x] 順便補了 `docs/api.md` 缺漏的 Phase 7 admin 端點（原本的表格漏列，寫 OpenAPI 時
      對照 routes/api.php 才發現）

## Phase 12 — GitHub Actions CI ✅ 已完成 2026-08-24

- [x] `.github/workflows/ci.yml`：Backend job（Pint --test → Larastan(PHPStan) →
      MySQL service container → migrate → PHPUnit）+ Frontend job（ESLint → vue-tsc →
      Vitest → npm run build），兩個 job 並行跑
- [x] ESLint／TypeScript／Vitest／build 都在 Frontend job 裡
- [x] 真的推上 GitHub、用 `gh run watch` 看過兩次實際執行（不是只寫完 yaml 就交差）：
      第一次跑就抓到 3 個只有在全新 checkout 才會炸的真 bug（見 progress.md），修完
      第二次兩個 job 都綠燈，`gh run view` 確認 exit code 0

已知限制：`docker/mysql/init/`、`scripts/setup-test-db.sh` 是給本機 docker-compose 用的，
CI 用的是 GitHub Actions 原生的 MySQL service container（`MYSQL_DATABASE` 環境變數直接建庫），
兩條路徑不共用同一個腳本，但邏輯上是等效的（見 workflow 檔案裡的註解）。

## Phase 13 — 部署文件 ✅ 已完成 2026-08-24

- [x] `docs/deployment.md`：方案 A（EC2+RDS+ElastiCache，推薦）與方案 B（ECS Fargate）
      架構比較＋為什麼選 A、RDS/ElastiCache/EC2 佈建、`.env` production 覆寫清單、
      建置步驟、admin 帳號建立、Queue Worker／排程建議、安全性（真的重新跑
      `composer audit` 驗證 `CVE-2026-48019` 現況還在，不是憑記憶抄）、回滾策略
- [x] 只產出文件，沒有實際執行部署（沒有 AWS credentials，也沒有使用者確認要花錢起 infra）
- [x] 文件開頭列出「目前還不是 production-ready」的已知缺口對照表，不是寫得像萬事俱備

## 補做：`RuleBasedRecommendationService` ✅ 已完成 2026-08-24

重新對照總體規劃 md（第 29/30 節）跟 `docs/architecture.md`「AI 預留」段落才抓到的落差——
Phase 0 就設計好、Phase 9 卻直接用前端 client-side sort 打發沒做的東西，補齊細節見
progress.md。

- [x] `config/recommendation.php`、`RecommendationServiceInterface`／
      `RuleBasedRecommendationService`、`AppServiceProvider` 綁定
- [x] `RestaurantRepository::candidatesForRecommendation()`、
      `GET /api/v1/restaurants/recommended`、`RestaurantResource.recommendation_score`
- [x] `HomeView.vue` 首頁「推薦餐廳」改接真的後端排序，不再是前端 sort by rating
- [x] 5 個新測試（3 個 Service 邏輯＋2 個 HTTP），`docs/openapi.yaml`／`docs/api.md`／
      `docs/architecture.md` 同步更新

## 補做：Redis Search/Detail Cache + Rate Limiting ✅ 已完成 2026-08-24

同一輪重新對照 md 抓到的第二個、也是更嚴重的落差——「Redis Cache」「Rate Limiting」是
總體規劃開頭核心能力清單項目，第十六/十七節明講要做，查證後發現是 0% 實作（只有
geocode 有 cache），已補齊，見 progress.md 的詳細記錄與三步自我驗證（拔掉重跑測試、
真的看 Redis KEYS、curl 看 rate limit header）。

- [x] `RestaurantRepository::search()`／`findForDetail()` 包 `Cache::tags()`／
      `Cache::remember()`，300s／600s TTL
- [x] `RestaurantObserver`／`RestaurantConfidenceScoreObserver` + 
      `RestaurantCacheInvalidator`：寫入後清快取，不用 `Cache::flush()`
- [x] `AppServiceProvider::boot()` 的 `RateLimiter::for('api', ...)`，60/分鐘，
      Redis-based，套用到整個 `/api/v1/*`
- [x] 6 個新測試（直接斷言 DB query 數為 0，不只驗證回應內容）
- [x] `docs/api.md` 新增 Caching／Rate Limiting 段落，`README.md` 的 Caching
      Strategy／Security 段落改成反映真實現況（原本是超前於實作的敘述）

## 已知技術債（progress.md 記錄，一併排入）✅ 全部完成 2026-08-24

- [x] `users:promote {email}` Artisan 指令（目前只能手動改 DB 升 admin）✅ 2026-08-24
- [x] 安裝 Laravel Horizon，把 `dispatchSync()` 改回 `dispatch()`（目前沒有 queue worker，
      所有 Job 用 `dispatchSync` 頂著，見 [progress.md](progress.md) Phase 6 決定）
      ✅ 2026-08-24——`docker-compose.yml` 新增 `horizon` container，5 個呼叫點全部改回
      `dispatch()`，真實驗證過非同步處理生效（見 progress.md）
- [x] `routes/console.php` 排程：定期跑 `restaurants:sync`、
      `restaurants:recalculate-ratings`、`restaurants:calculate-scores`（目前只能手動執行）
      ✅ 2026-08-24——細節見 progress.md，`restaurants:sync` 因為沒有正式決定過涵蓋範圍，
      改用 `EXTERNAL_API_SYNC_BBOXES` 環境變數控制，留空就不排程

## 補做：Database ERD ＋ 架構圖改用 Mermaid ✅ 已完成 2026-08-24

總體規劃「最終完成標準」點名要的兩項視覺化產出，之前只有文字表格／ASCII art，這次
一併補齊，細節見 progress.md（含用 `mermaid-cli` 實際渲染驗證、抓到並修正 2 個真的
語法錯誤、順便修掉架構圖裡兩個從沒實作過的幽靈 Job 名稱）。

- [x] `docs/database.md` 新增 `## ERD`（Mermaid `erDiagram`，13 張核心表）
- [x] `docs/architecture.md`／`README.md` 的系統圖改成 Mermaid `flowchart`，對照現況重畫
- [x] README「Database Design」順便修正一個既有的表格數量小錯誤

## 補做：`EXTERNAL_API_SYNC_BBOXES` 填入台北市 ✅ 已完成 2026-08-25

排程功能在 2026-08-24 就裝好了，但預設留空代表「裝好了卻不會跑」，等的是產品端決定
涵蓋範圍。使用者 2026-08-25 決定：預設涵蓋台北市。

- [x] `.env.example`／`.env` 的 `EXTERNAL_API_SYNC_BBOXES` 填入台北市行政區範圍
      `24.9613,121.4570,25.2130,121.6663`
- [x] `tests/Feature/Console/ScheduleTest.php` 改寫成不依賴環境變數（原本斷言「預設空
      陣列」，前提已被這次決定推翻）：改用注入 config 重跑 `routes/console.php` 的方式，
      分別驗證「空設定不排程」「每組 bbox 各產生一條排程」兩個分支，另加一條確認預設
      env 真的是台北市。含反向驗證：把 `foreach` 的來源換成空陣列，測試真的會紅。
- [x] `routes/console.php` 兩段過時註解一併更正（一段還停留在 Horizon 之前的
      `dispatchSync` 敘述，一段寫「沒有正式決定過涵蓋範圍」）

## 補做：provider 切 osm ＋ Overpass 查詢只抓純素食店 ✅ 已完成 2026-08-25

- [x] `.env` 的 `EXTERNAL_API_RESTAURANT_PROVIDER` 改 `osm`（`.env.example` 維持 `mock`
      安全預設，CI／新 clone 不會打外部 API）
- [x] `OsmRestaurantProvider::buildQuery()` 加素食篩選：union `diet:vegetarian=only`
      與 `diet:vegan=only`。原本沒有任何篩選，台北市 bbox 會撈回 15,974 家而不是 222 家
- [x] 修 HTTP 406：Overpass 擋 Guzzle 預設 User-Agent，新增 `overpass.user_agent` config
      （`?:` 而非 `env()` 第二參數，避免空字串繞過預設）與 `withHeaders`
- [x] `catch (RequestException)` 取回真實狀態碼，log 記 `HTTP_406` 而非 `RequestException`
- [x] 新增 `tests/Feature/External/OsmRestaurantProviderTest.php`（6 條，原本零覆蓋），
      含反向驗證：拔掉 diet 篩選 2 條紅、拔掉 UA header 1 條紅
- [x] 小 bbox 實跑驗證：created 106，與事前 `out count;` 預期值一致，零非素食店混入
- [x] `docs/external-apis.md` 補上素食篩選規則與 406 失敗模式

未完成：全台北市 bbox 尚未實跑（小 bbox fetch 花 17.3s／timeout 30s，全市值得先實測）；
「vegan=only 是否自動蘊含 vegetarian」留待產品決定。細節見 progress.md。

## 補做：預設涵蓋台中市，東京 23 区規劃進去 ✅ 已完成 2026-08-25

- [x] 預設 bbox 從台北改台中，且**先量再定**：原本抓的範圍量到 166 筆，放寬南／西緣後
      177 筆，確認漏了 11 家才定案 `23.9500,120.4300,24.4500,121.4700`
- [x] 台中實測：created **177**、13.5 秒，與 `out count;` 預期值一致，零非素食店混入
- [x] 東京 23 区 `35.5300,139.5600,35.8200,139.9200` 加進 `EXTERNAL_API_SYNC_BBOXES`
      （分號分隔第二組），`schedule:list` 確認產生兩條獨立排程
- [x] `ScheduleTest` 原本鎖死台北的斷言改成台中＋東京，並新增分號分隔解析的測試

其他未決：台北那 106 筆測試匯入資料仍留在 DB；本機沒有 scheduler container，排程
實際不會自動跑。細節見 progress.md。

## 補做：收錄規則依國別而異（台中 only／東京 yes）✅ 已完成 2026-08-25

- [x] `EXTERNAL_API_SYNC_BBOXES` 格式升級成 `bbox@規則`，`sync_bboxes` 改名 `sync_regions`
      並回傳 `['bbox' => ..., 'diet' => ...]`，`routes/console.php` 排程帶上 `--diet`
- [x] `OsmRestaurantProvider` 建構子收 `only`／`yes`，未知值 throw；`yes` 模式查詢是
      `~"^(yes|only)$"` 而非 `="yes"`（否則會漏掉純素食店）
- [x] `restaurants:sync --diet=` 選項；順帶修掉 `resolveProvider()` 對未知 provider 的
      靜默退回 mock
- [x] 東京實測：created **195**、17.3 秒。count 查詢說 210，查證差的 15 筆是 OSM 上沒有
      `name` 的節點（改查「有 name 的節點數」正好 195），不是漏匯入
- [x] `docs/external-apis.md` 新增「收錄規則」段落，含兩地標籤密度對照表
- [x] 反向驗證：拔掉排程的 `--diet` 傳遞，測試真的紅

**已知後果（知情決定，非資料錯誤）**：東京 195 家裡包含 CoCo壱番屋、AFURI、
ドトールコーヒーショップ 這類「有純素選項的連鎖店」，這是 `yes` 規則的必然結果。

## 補做：加入高雄、台南，台北排回排程 ✅ 已完成 2026-08-25

- [x] 高雄市 `22.4500,120.1500,23.3000,121.0600@only` — 107 筆
- [x] 台南市 `22.8500,120.0000,23.4500,120.7000@only` — 45 筆
- [x] 台北市排回 `sync_regions`（留著當多城市資料就該保持更新，否則會過時）
- [x] 高雄 bbox 校正：第一版北緣 23.50 整個吞掉台南（跑台南時 created 0／updated 45
      才發現），實際最北約 23.30，校正後 107 筆而非 114
- [x] 冪等性驗證：重跑高雄 created 0／updated 107，`source_id` 唯一值 592 = 匯入總數
- [x] 新增 `test_only_tokyo_uses_the_relaxed_diet_rule`：五個範圍恰好 4 個 only、1 個 yes
- [x] 更正 `docs/external-apis.md` 一個我先前算錯的數字（台中 only 佔比 80% → **74%**，
      原本拿放寬 bbox 的分子配窄 bbox 的分母），並修掉「台灣都標 only」這個過度概括——
      高雄約 35%、台南約 24%，跟東京 22% 相去不遠

**現況**：612 筆＝種子 20 ＋ OSM 592（台北 106／台中 177／高雄 107／台南 45／東京 195）。

未決：本機沒有 scheduler container；台南 45 家若嫌少可改 `yes`（187 家）但會與其他台灣
城市標準不一致。細節見 progress.md。

## 補做：前端多城市切換 ＋ UI/UX 優化 ✅ 已完成 2026-08-25

- [x] `config/cities.php` ＋ `GET /api/v1/cities`，`CitySwitcher.vue` 依國家分組
- [x] **不用 `city` 欄位篩選**——查證後發現 59% 是空的、「臺中市／台中市」兩種寫法、
      東京填的是「渋谷区」，一律走 bbox 座標
- [x] `CitiesTest` 綁住 `config/cities.php` 與 `sync_regions` 不會漂移（雙向檢查＋
      center 必須落在自己 bbox 內）
- [x] 網址 `?city=` 當單一真相來源，上一頁／重新整理／分享連結都正確
- [x] **修 Leaflet `flyTo` 長距離破圖**：跨 200km 動畫會把磁磚排到容器外 13,000px，
      marker 卻正常。新增 `jumpTo()`，`flyTo()` 加 50km 距離防護
- [x] **修計數謊報**：cursor 分頁沒有總數，`per_page=100` 是上限，改成「100+ 家」
- [x] **修競態**：地圖移動／改篩選／換城市三個來源會互相蓋掉，加請求序號
- [x] FilterDrawer 改成真抽屜（手機預設收合，地圖上移約 145px）、補「清除」、
      補空狀態與錯誤狀態、a11y（aria-pressed／aria-expanded／focus-visible）
- [x] 修既有 bug：`filters.diet = undefined` 留下 key 導致篩選計數算錯，改用 `delete`

未完成：FilterDrawer 的 media query 監聽「中途改尺寸自我補正」只有推理沒有實測（該環境
不派送 resize 事件）；`RestaurantListView` 尚無城市概念。細節見 progress.md。

## 補做：前端元件測試（3 → 32 個）✅ 已完成 2026-08-25

上一項點名的缺口。手動驗證在內嵌瀏覽器會卡在輸入層，改用元件測試守住邏輯。

- [x] `@vue/test-utils` + `jsdom`，`resources/js/test/setup.ts` stub `matchMedia`
- [x] `CitySwitcher.test.ts`（5）：分組、active、**送 slug 不送 label**、aria-pressed
- [x] `FilterDrawer.test.ts`（8）：收合預設、**delete 而非 undefined**、徽章、清除
- [x] `RestaurantMap.test.ts`（5）：**短距離 flyTo／長距離 setView** 的距離門檻，
      也就是台北切台南破圖那個 bug 的防線
- [x] `HomeView.test.ts`（11）：網址優先／localStorage 備援／未知 slug 不留白／
      切換帶對 center+zoom／計數 100+ 與空狀態
- [x] 反向驗證：拔距離防護 2 條紅、拔 slug fallback 1 條紅
- [x] CI 的 Frontend job 本來就有 `npm run test`，不用改 workflow

未完成：`SearchBox`／`AdminView` 仍無測試；仍無真瀏覽器 E2E（維持 Phase 10「這個規模
ROI 偏低」的判斷）。

## 補做：RestaurantListView 城市切換 ＋ `bbox` API 參數 ✅ 已完成 2026-08-25

- [x] 新增 `bbox=minLat,minLng,maxLat,maxLng` 查詢參數。**既有兩種收窄方式都不能用**：
      `city` 欄位不可靠；`radius` 上限 50km 而台中半對角線 59.6km、高雄 66.4km（實測 422）
- [x] `RestaurantRepository` 抽出 `polygonFromCorners()` 給 bbox 與半徑兩條路徑共用；
      帶 bbox 時不再套半徑截斷（會把矩形四角切掉）
- [x] 格式錯的 bbox 一律 422，不靜默忽略（忽略＝從「查這座城市」變成「查全世界」）
- [x] 抽出 `useCities` composable 給首頁與列表頁共用，`fallback` 區分兩頁差異；
      列表頁維持原本「列出全部」的預設，`CitySwitcher` 加 `allowAll`
- [x] 修掉自己引入的雙重請求（實測 network 面板每次進頁面送兩發，改成單一觸發）
- [x] 後端 12 條 bbox 測試＋前端 11 條列表頁測試，含反向驗證（後端 4 紅、前端 5 紅）

過程中兩個測試失敗查證後都是測試錯不是程式錯：`MBRContains` 邊界是嚴格排除（SQL 驗過）；
測試資料庫沒 seed lookup 表所以 `Feature::where(...)->value('id')` 是 null。

未完成：匯入資料 `address` 常為空字串導致卡片多一行空白（既有問題，首頁也有）。
細節見 progress.md。

## 補做：列表頁關鍵字進網址 ✅ 已完成 2026-08-25

- [x] 草稿（輸入框）與已提交關鍵字（網址）分開：只有按搜尋／Enter 才寫進網址，
      否則按上一頁會變成**逐字倒退**而不是回到上一次的搜尋
- [x] `watch(committedKeyword)` 回填輸入框，上一頁／改網址時畫面與結果不會對不起來
- [x] 關鍵字併進 `searchScope` 單一觸發機制，換城市／改關鍵字／首次載入都只送一發請求
- [x] 按搜尋但關鍵字沒變時直接查詢（router 不會觸發 navigation，否則按了沒反應）
- [x] 新增「清除」鈕：只移除 keyword，city 留著
- [x] 空狀態改成 `emptyMessage` ＋ `emptySuggestions`，只列適用的建議（沒下關鍵字就不會
      叫人「換個關鍵字」），也不會再拼出斷尾的句子
- [x] 前端測試 43 → 54，含反向驗證 3 條紅；瀏覽器實測回填／保留／上一頁／清除四件事

## 補做：filters 進網址（逐個 query 參數）✅ 已完成 2026-08-25

- [x] `useFilterQuery` 可寫 computed，`?diet=vegan&parking=1`；布林用 `1`，關閉就是
      沒有該參數（不用 `0` 佔位）；首頁與列表頁都改用，行為一致
- [x] FilterDrawer 從「就地改欄位」改成整組替換——接到網址後就地改會落在暫時物件上
      傳不出去；順便消掉兩條路徑行為不一致
- [x] **抓到既有 bug：「寵物友善」「停車」從實作出來就沒運作過**。axios 把布林序列化成
      `"true"`，Laravel `boolean` 規則不吃，一直回 422；舊版沒有錯誤處理所以靜默失敗。
      新增 `apiFilterParams()` 在請求邊界轉成 `1`，並補測試釘住
- [x] 前端測試 54 → 74，含反向驗證 6 條紅；瀏覽器實測回填／寫入／清除／上一頁

未完成：OSM 匯入沒帶入任何 feature（592 筆全都沒有），所以這兩個篩選在真實資料上仍是
空的；API 不接受 `parking=true` 只接受 `1`/`0`。細節見 progress.md。

## 現況：總體規劃全部 Phase（0～13＋8.5）＋ 已知技術債＋兩輪 gap analysis 全部完成

`docs/progress.md` 逐項記錄；`git log` 每個 commit 都有對應 GitHub Actions CI 綠燈
（`gh run list` 可查）。下次接手前建議先讀 `docs/progress.md` 最新幾則條目，特別是
「多個 Claude Code session 共用同一個測試資料庫」那則已知環境限制——如果同時開多個
session 對這個 repo 工作，`php artisan test` 偶爾會出現隨機失敗，不代表程式碼壞了。
