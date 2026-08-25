# TODO — 剩餘規劃

> 用途：Claude Code 下一次接手時的進度追蹤清單。完成一項就打勾＋commit push（見
> [progress.md](progress.md) 的 commit/push 慣例），不要一次全做。
>
> 2026-08-25 重新對照總 Prompt 後：Phase 0～13 主線已完成。
> **下一批產品工作是 P0 葷素混合店 Phase A→B→C**；其餘未閉環項目在「剩餘：規劃明寫但還沒閉環」。

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

未完成：API 不接受 `parking=true` 只接受 `1`/`0`。細節見 progress.md。

## 補做：OSM 同步帶入 features ✅ 已完成 2026-08-25

- [x] 先抓台中 177＋東京 210 筆節點統計標籤分布再決定對應，不憑印象
- [x] 對應表是「標籤 → 特色 ＋ **值的白名單**」：`outdoor_seating=no` 有 32 筆比 `yes`
      的 10 筆還多，只看 key 存在會把明確說沒有的店標成有
- [x] `parking`／`family_friendly` 查證後 OSM 無可用標籤（387 筆節點 0 筆），不硬湊對應
- [x] `syncWithoutDetaching`：自動同步不會洗掉手動加上的特色
- [x] 五個城市實跑：有 features 的匯入資料 0 → **138** 筆
      （takeout 111／wifi 19／outdoor_seating 18／delivery 14／reservation 9／pet_friendly 3）
- [x] 順帶把台北補成完整市範圍（原本只跑過市中心小 bbox），總數 592 → 708
- [x] 後端測試 107 → 132，含反向驗證 3 條紅

## 補做：泛用特色篩選 ＋ 接受 `true`／`false` 字串 ✅ 已完成（`f538d1b`）

上一則點名的落差。沒有改成 `?feature=takeout`（那會動到既有 `pet_friendly=1` 契約），
改成每個 `features.code` 都是獨立布林參數，跟原本兩個篩選同一套約定。

- [x] `Feature::CODES` 當單一真相來源；`SearchRestaurantRequest`／
      `RecommendedRestaurantRequest` 用 `booleanFilterRules()`，不再寫死兩個
- [x] `RestaurantRepository::baseQuery()` 對 8 個 code 一律 `whereHas`
- [x] FilterDrawer 依 `/features` 動態渲染全部晶片
- [x] `prepareForValidation()` 把 axios 的 `"true"`／`"false"` 轉成 `1`／`0`
      （上一則列的「API 不接受 `parking=true`」一併修掉）
- [x] 首頁推薦 API 也吃同一組篩選（修掉「地圖有篩、推薦沒篩」）

## 補做：空地址不渲染、scheduler container、未知 provider 要 throw ✅ 已完成

先前進度條裡列成未決、後來已經補上、但這份 todo 沒打勾的三件：

- [x] 列表／首頁推薦／詳情／地圖 popup：`address?.trim()` 為空就不渲染那一行
      （OSM 沒有 `addr:street` 時不再多一行空白）
- [x] `docker-compose.yml` 新增 `scheduler` service（`php artisan schedule:work`），
      本機排程不再只存在於 `schedule:list`
- [x] `AppServiceProvider` 對未知 `EXTERNAL_API_RESTAURANT_PROVIDER` 直接 throw，
      不再靜默退回 mock（填 `overpass` 會看起來成功、一筆真資料都沒進來）

## 現況（2026-08-25 對照總 Prompt 後）

Phase 0～13＋8.5 與兩輪 gap analysis 的**主線都做完了**，但總 Prompt 裡仍有一批
「寫進規格、後來擱置或只做半套」的項目。下面依「規劃有沒有明寫」整理，不是憑空加功能。
完成一項就打勾＋更新 [progress.md](progress.md)，不要一次全做。

**下一批產品工作**：葷素混合店 Phase A → B → C（見下方 P0）。設計原則是活用型——
標籤對應、收錄規則、預設篩選、文案分組全部走 config／API，禁止在 Controller、
Repository、Vue 元件裡寫死 code 清單或「台灣一定 only」。

---

## P0 — 葷素混合店（Phase A／B／C）

來源：2026-08-25 產品決定。地圖要同時能呈現：

1. **純素食店**：整間都是素／全素（OSM `diet:*=only`）
2. **素食友善店**：葷素都有，菜單有無肉選項（OSM `diet:*=yes`）
3. **菜單層**：有資料時標每一道是素／葷；沒資料就誠實不畫假菜單

東京已用 `@yes` 在收友善店，但映射把 `yes` 和 `only` 都寫成 `vegan`／`vegetarian`，
畫面上友善店看起來像素食餐廳。台灣四市仍 `@only`。`diet_types` 已有
`vegan_friendly`／`vegetarian_friendly`，`menu_items.diet_type` 已有 `non_vegetarian`，
都還沒接到真實資料流。

### 活用型約束（三個 Phase 都要遵守）

實作時若發現自己在寫 `if ($code === 'vegan')` 或 `if (city === 'taichung')` 這種
產品規則，停下來改成讀 config。具體：

- **禁止**在 `OsmRestaurantProvider` 寫死 `diet:vegan → vegan`。對應表放 config
  （比照 `FEATURE_TAG_MAP`，但那份也該一併搬出 class const）。
- **禁止**在 FilterDrawer／前端寫死「全素／素食友善」兩組 chip。分組與標籤由
  `GET /diets` 帶回 metadata，元件只做分組渲染。
- **禁止**用國家或城市名硬切收錄規則。繼續走 `EXTERNAL_API_SYNC_BBOXES` 的
  `bbox@規則`；規則名稱本身也要來自 config 白名單，不是 provider 裡的兩個 const。
- **禁止**在推薦／可信度裡寫死「友善店少 20 分」。權重放 `config/vegetarian.php` 或
  新的 `config/diet.php`。
- **禁止**菜單列舉 `vegan|vegetarian|non_vegetarian` 散落在 FormRequest／Vue。
  合法值與顯示標籤同一份 config，seeder／驗證／前端都讀它。
- 前端若需要在打 API 前就知道名單（網址 parse），只允許一份與後端同源的匯出
  （例如 seeder／config 產生的 ts，或啟動時打 `/diets`；不要再手寫一份
  `FEATURE_CODES` 式的平行清單）。現有 `FEATURE_CODES` 這次能順便改更好，不是本項必做。

建議新增 **`config/diet.php`** 當單一真相來源，至少包含：

```text
types[]            code / label / kind(exclusive|friendly) / osm_tag / osm_values[]
sync_modes[]       名稱（only、yes、…）→ Overpass 值的 regex 或白名單
implies[]          可選：vegan only 要不要同時掛 vegetarian（預設關，用設定打開）
venue_scope        查詢參數名、合法值（exclusive / friendly / all）、預設值
menu_item_diets[]  vegan / vegetarian / non_vegetarian / unknown 的 code+label
confidence         exclusive vs friendly 對 external_source／「店家標示素食」的權重
copy               卡片／詳情用的短文案 key（純素食店 vs 菜單有素食），前端讀 API 不要寫中文在元件裡
```

`DietTypeSeeder` 改讀這份 config upsert，不再在 seeder 裡維護第二份清單。
`GET /diets` 除了 `code`／`label` 要帶回 `kind`（與可選的 `group_label`），
FilterDrawer 才能動態分組。`kind` 可以是 DB 欄位或 Resource 當下查 config——
不要在 Vue 寫 `['vegan','vegetarian'].includes(code)`。

預設篩選（進首頁沒帶 query 時）也放 config，建議預設 `venue_scope=exclusive`，
避免 Phase B 一開台灣友善店，素食使用者以為點進去整間都能吃。改預設只改 config。

---

### Phase A — 分得清（映射＋篩選＋顯示）

目標：東京已匯入的友善店標籤改對；台灣店數暫時不變。完成前**不要**改
`EXTERNAL_API_SYNC_BBOXES` 的 `@only`。

- [x] `config/diet.php`（或同等結構）＋ seeder／`DietTypeResource` 吃同一份
- [x] OSM 映射：`only` → exclusive codes，`yes` → friendly codes；Overpass 的
      tag 清單與值白名單讀 config，`OsmRestaurantProvider` 只負責組 query／parse
- [x] 重跑東京 sync（映射修正是 upsert diet 關聯；用 `syncWithoutDetaching` 的話
      **舊的錯誤 `vegetarian` 掛在友善店上不會自己掉**——要想清楚：OSM 管得到的
      diet 關聯該不該改成「同步這次算出來的集合」，手動加的才 `withoutDetaching`。
      這是設計點，寫進 progress 再做，不要兩種關聯用同一套卻留下錯標）
- [x] 搜尋 API 新增 `venue_scope`（名稱以 config 為準）：`exclusive`／`friendly`／`all`
      驗證規則從 config 來，Repository 依 `kind` 過濾，不寫死 code 陣列
- [x] FilterDrawer：範圍（純素食店／素食友善／全部）＋既有細項 diet chip，
      全部依 `/diets` 的 `kind`／label 渲染；選取寫進網址
- [x] 卡片／popup／詳情：exclusive 與 friendly 用不同標籤與短文案（文案來自 API／config）
- [x] 可信度：友善店不要套「店家明確標示素食」那檔分數；權重在 config
- [x] 測試：映射單元（yes→friendly、only→exclusive）、HTTP 篩選、前端分組；
      反向驗證：config 裡拿掉某個 osm_values，對應測試要紅
- [x] 文件：`docs/external-apis.md` 收錄規則、`docs/api.md`／OpenAPI 補 `venue_scope`

Phase A 完成的驗收：東京抽樣（CoCo／AFURI 之類）顯示「素食友善」而不是「素食」；
台中十方齋仍是 exclusive；`?venue_scope=exclusive` 從東京結果裡拿掉友善店。

---

### Phase B — 台灣也收友善店

目標：台灣四市改收 `diet:*=yes|only`。**Phase A 沒合上不要開始**——否則多出來的店
會全部標成素食餐廳。

- [x] `.env`／`.env.example` 的台灣 bbox 從 `@only` 改成 `@yes`（規則名仍是 config
      白名單裡的那個「含 yes+only」模式，不要新發明第三種寫死在 PHP 的字串）
- [x] `ScheduleTest` 裡「四個 only、一個 yes」那條會紅，改成斷言「每個 region 的
      diet 都在 config 白名單」＋「東京用含 yes 的模式」；不要再寫死四／一的數量
- [x] 先實跑**一個**台灣城市（建議台南，45→約 187，最好抽樣），timeout／筆數對過
      Overpass `out count;` 再全開其餘三市
- [x] 全開後抽樣：友善店有 `*_friendly`、純素食店仍是 exclusive；
      `whereDoesntHave('dietTypes')` 仍為 0
- [x] 預設 `venue_scope` 維持 exclusive（config），首頁數字不會突然變成「全是火鍋」；
      使用者要看混合店得自己點「含素食友善」或「全部」
- [x] 更新 `docs/external-apis.md` 收錄表（台灣改 yes 的理由改成產品決定，
      不要再寫「台灣為了標準一致用 only」）

Phase B 完成的驗收：台南／台中／台北／高雄 bbox 內都有 friendly 店；預設篩選下
首頁仍以純素食店為主；分享 `?venue_scope=all` 看得到混合店。

---

### Phase C — 菜單層葷／素

目標：有菜單資料就分組顯示；沒有就說明「標示有素食選項，菜單尚未建檔」。
**不要**為了填菜單去接 Open Food Facts 或隨機食物圖。

- [ ] 菜單 `diet_type` 合法值與 label 來自 `config/diet.php` 的 `menu_item_diets`；
      FormRequest／Resource／前端共用，不在三處各寫一份 enum
- [ ] 詳情頁：有 `menu_items` 才渲染分組（素食／全素／葷食／未標示）；空陣列走
      誠實空狀態，文案依該店 `kind` 而變（friendly vs exclusive）
- [ ] 種子／factory 的菜單 diet 從同一份 config 抽，不要 factory 裡寫死四個字串
      卻跟 config 漂移
- [ ] 寫入 API（使用者或之後的店家）：`POST /restaurants/{id}/menu-items`
      （或先只做 Admin），驗證 diet_type ∈ config；Policy 先最小（登入或 admin）
- [ ] OSM 仍然沒有逐道菜單——sync **不編造** menu_items。可選：OSM `cuisine` 等
      標籤若要當提示，也必須走 config 對應表，沒對上就忽略
- [ ] 回報 `menu_changed`／`not_vegetarian` 核准後的動作放 config 對照表
      （例如 exclusive 店核准 not_vegetarian → 降為 friendly 或下架；friendly 店
      → 拿掉 exclusive codes）。不要在 Admin controller 寫死 switch。
- [ ] 測試：無菜單友善店不渲染假菜色；有菜單才分組；非法 diet_type 422

Phase C 完成的驗收：種子餐廳詳情看得到葷／素分組；OSM 匯入的友善店詳情沒有假菜單、
有「尚未建檔」說明；新增一筆菜單（若做了寫入 API）會出現在對應分組。

---

實作順序必須 A → B → C。A 的映射與 `venue_scope` 是 B／C 的前提。
每階段做完跑測試、更新 progress.md，再進下一階段。

---

## 剩餘：規劃明寫但還沒閉環

對照來源：`VeggieMap — Claude Code 專案開發總 Prompt.md`。括號裡是原文章節。

### P1 — 產品核心還沒有完整資料流

這些是總 Prompt 當成特色／搜尋核心寫的，管線或表已經在，但使用者／Admin 走不到。

- [ ] **素食可信度的寫入路徑（第十一節）**
      計算 Job、`config/vegetarian.php`、OSM 匯入寫 `external_source` 都有了。
      其餘五種 `verification_type`（`restaurant_claim`／`menu_verified`／`user_report`／
      `photo_verified`／`admin_verified`）**沒有任何 HTTP 端點或 Admin 動作會呼叫**
      `VerificationService::record()`。使用者回報核准也不會轉成 `user_report` 驗證。
      結果：真實匯入餐廳的 confidence score 幾乎只有外部來源那一截，第十一節列的加分項
      多數永遠是 0。最小可用做法：Admin 手動標記 `admin_verified`、回報核准時寫
      `user_report`（需先定義每種 report type 對不對應驗證）。店家認領／照片驗證屬
      Roadmap，不要為了湊類型硬做上傳。

- [ ] **`possible_duplicate` 供 Admin 審核（第二十二節）**
      同步時「同名＋距離 <100m」會把兩筆都標 `is_possible_duplicate=1`，**不自動刪**。
      Admin API／`AdminView` 都沒有列出或合併／駁回重複的入口，標記等於沒人看。
      需要：`GET /admin/duplicates`（或既有 admin 加一個分頁）＋明確的「保留／忽略」
      動作（不要做成自動合併）。

- [ ] **`open_now`／營業時間（第八、二十八節）**
      搜尋參數與首頁 UI 都寫了「營業中」。schema 沒有 `opening_hours`，Phase 3 決議擱置、
      `docs/api.md` 參數列表卻還留著——文件超前於實作。要做的話：OSM 有 `opening_hours`
      標籤可同步、存成欄位或獨立表，再做 `open_now` 篩選；不做就把參數從 api.md／
      OpenAPI 拿掉，不要留一個永遠 422 或被忽略的參數。

- [ ] **回報核准後要不要動餐廳（第十二、十八節）**
      `ProcessUserReportJob` 規劃有、後來改成同步寫入（知情決定，不必再做一個空 Job）。
      真正缺的是規則：`type=closed` 核准後要不要把 `restaurant.status` 改 `inactive`、
      `not_vegetarian` 要不要撤 diet／降 confidence。Phase 7 刻意不猜。需要產品先定
      對照表，再接到 Admin approve。

### P1 — 規劃明寫的體驗／契約缺口

- [ ] **餐廳詳情走 slug（第二十六節）**
      規劃路由是 `/restaurants/{slug}`。DB 有 `slug`（含中文名 fallback），Resource 也
      回傳，但 `GET /restaurants/{id}` 與前端都用數字 id。改成 slug 查詢（保留 id 相容
      或 301）才符合「人類看得懂的 URL」。中文店名的 slug 目前是 `osm-node-123`，
      改路由前要想清楚要不要另外做可讀別名。

- [ ] **可瀏覽的 API 文件掛在 `/docs`（最終完成標準）**
      有 `docs/openapi.yaml`，lint 過。網站上沒有 Swagger UI／Redoc，clone 下來看不到
      「http://localhost:8080/docs」。最小做法：用 `darkaonline/l5-swagger` 或靜態
      Redoc 頁吃同一份 yaml，production 可選擇只在 local 開。

- [ ] **Circuit breaker（第二十節、`docs/external-apis.md`）**
      timeout／retry／log／fallback 都有。連續 N 次失敗後停止該次 `restaurants:sync`
      （建議 5 次）沒做。現在排程一次打 5 個城市 bbox，Overpass 掛掉會連打 5 次才結束，
      這時候斷路器才有意義。

- [ ] **搜尋 UI 沒接上的 API 參數（第八、二十八節）**
      後端有 `price_level`、`rating_min`、`district`，前端 FilterDrawer 只有 diet＋
      features。首頁規劃的晶片是「全素／蛋奶素／**素食友善**／寵物友善／停車／營業中」。
      「素食友善」改由上面 **P0 Phase A** 的 `venue_scope`＋`/diets` 分組處理，不要
      在這裡另做一顆寫死的 chip。價位／評分是加分項；`open_now` 仍見營業時間那項。
      `district` 因 OSM 資料品質差，維持 API-only 即可。

- [ ] **`/profile` 極簡（第二十六節）**
      頁面在，只能看 name／email／role，不能改資料或密碼。規劃寫了「使用者」頁，沒寫
      編輯範圍。最小：改 display name＋改密碼（FormRequest＋現有密碼確認）。

- [ ] **使用者改／刪自己的評論（第二十五節）**
      Policy 原文是「不能改別人的 Review」，暗示自己的可以。`ReviewPolicy` 目前只有
      `create`／`moderate`，沒有 PATCH／DELETE 端點。重新送一則會覆蓋（hidden 舊的），
      所以「改」有曲線；「刪」完全沒有。若做，才需要 `update`／`delete` Policy。

### P1 — 安全／可觀測性（規劃「至少」）

- [ ] **`CVE-2026-48019`（第四十二節、`docs/deployment.md` 標「部署前必須」）**
      Laravel 11.x 預設 email 驗證規則的 CRLF injection。修法是升到 12.60+／13.10+，
      或在 FormRequest 額外擋。`composer audit` 還會報。沒部署前可以繼續放，但不要
      假裝 Security 章節已經處理完。

- [ ] **Observability 三缺（第三十五節）**
      `docs/observability.md` 已誠實記錄未做：
      - 一般 API 的 response time（只有外部呼叫有 `response_time_ms`）
      - Cache hit／miss 分 key 追蹤（Redis `INFO stats` 是全域，應用層沒記）
      - DB 慢查詢（沒有 `DB::listen`、也沒開 MySQL slow query log）
      Telescope 只在 local。若要履歷上的「Logging / Monitoring」站得住，至少做一個
      輕量 request timing middleware，或上 Laravel Pulse；不要為了表格好看接一堆 APM。

- [ ] **列表 API 仍是整列撈出（第三十二節）**
      規劃寫大型列表不要 `SELECT *`。`RestaurantRepository::search()` 沒有
      `select()` 收欄位，列表不需要 `description`／`source_id` 等。資料量還小所以
      感覺不出來；要動的話連 Resource／cache key 一起收斂，避免 detail／list 共用
      同一個 model 形狀卻漏欄位。

### P2 — 文件與程式碼不一致（改文件也算待辦）

這些不是缺功能，是文件還停在舊決定，面試官對照會以為沒做或做了其實沒有。

- [ ] **`docs/deployment.md` 開頭缺口表過時**
      仍寫「沒有 Horizon／沒有 `users:promote`／沒有排程」。三項都已做完。應改成反映
      現況，並把還真的沒做的（CVE、Nominatim 商業政策、本文件的 P1）留下來。

- [ ] **`docs/observability.md` Queue 段落過時**
      仍寫 Job 用 `dispatchSync()`、`failed_jobs` 實務上不會有資料。Horizon 之後已改
      `dispatch()`，這段會誤導。

- [ ] **`docs/api.md` 寫了不存在的 Policy**
      寫 `RestaurantPolicy`、`FavoritePolicy`、`ReportPolicy`。實際只有
      `ReviewPolicy`、`RestaurantReportPolicy`。收藏刻意不做 Policy（只判斷已登入），
      餐廳沒有寫入端點所以沒有 RestaurantPolicy。把文件改成現況，或真的補檔——不要
      文件列四個、repo 只有兩個。

- [ ] **`docs/api.md` 仍列 `open_now`**
      跟上面 P1 同一件事：要嘛實作，要嘛從參數列表與 OpenAPI 刪掉。

### P2 — 測試缺口（已判定過 ROI，仍列出來備查）

- [ ] `SearchBox`／`AdminView`／`RestaurantDetailView` 仍無元件測試
- [ ] 沒有 Playwright／真瀏覽器 E2E（Phase 10 判斷這個規模 ROI 偏低，維持）
- [ ] OpenAPI 沒有 contract test（Dredd／Schemathesis）；寫規格時只手動抽測過部分端點

### P3 — 要產品決定才能動（不要擅自選）

- [ ] **`wheelchair` 是否加入 `features`**
      東京＋台中 OSM 共 52 筆，是目前最豐富卻沒用的標籤。`features` 表沒有對應項。
- [ ] ~~**`vegan=only` 是否自動掛 `vegetarian`**~~
      改由 P0 `config/diet.php` 的 `implies[]` 處理，預設關、用設定打開，不當寫死規則。
- [ ] ~~**台南要不要從 `only` 放寬成 `yes`**~~
      已由產品決定：Phase B 台灣四市（含台南）都改收友善店；預設篩選仍 exclusive。
- [ ] **匯入的 `city`／`district`／`address` 空字串 vs NULL**
      語意上 NULL 較正確，改了要連搜尋與前端空值判斷一起看。
- [ ] **要不要加 `LICENSE` 檔**
      OpenAPI 曾寫 MIT 被拿掉，因為 repo 根本沒有授權條款。
- [ ] **Horizon／Telescope production gate 白名單**
      目前空陣列＝production 沒人能看儀表板。真要部署再填 admin email。
- [ ] **Sanctum token 永不過期**
      MVP 可接受；正式營運要 expiry／refresh。
- [ ] **`FoodDataProviderInterface`／OpenFoodFacts（第十九節）**
      只是 Adapter 範例。目前沒有菜單營養資料需求，**不要為了架構圖對稱去接**。
      菜單本身：種子餐廳有假 `menu_items`，OSM 匯入餐廳幾乎沒菜單（OSM 沒這資料）。
      若要有菜單，來源是使用者／店家，不是再接一個食品 API。

### 明確不在這份待辦（總 Prompt 第四十三／四十五節）

AI 推薦、自然語言搜尋、Payment、Subscription、Notification、PWA、App、
店家後台、照片 OCR、User Reputation、Analytics——規劃寫「完成 MVP 後再考慮」。
上面 P1 閉環之前不要做這些。

---

下次接手前建議先讀 [progress.md](progress.md) 最新幾則，以及「多個 Claude Code
session 共用同一個測試資料庫」那則環境限制——同時開多個 session 跑 `php artisan test`
偶爾會隨機失敗，不代表程式碼壞了。
