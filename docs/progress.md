# Progress Log

## 2026-08-24 — Phase 0: Architecture & External API Research

**完成：**

- 建立 repo（`~/Documents/veggie-map`，全新 git repository，與 tower-defense 專案無關）。
- 逐一以 WebFetch 讀取原始文件確認（非憑記憶）：`public-apis` Food 分類、OSM Nominatim usage policy、
  Overpass API wiki、OpenStreetMap copyright/ODbL、Open Food Facts 官方說明。
- 結論：`public-apis` 清單無合適的免費「餐廳＋地理位置＋素食標籤」API，改採總 prompt 預期的
  OSM 路線——Overpass API 匯入 + 自建 Restaurant DB + MockRestaurantProvider 保底。細節見
  [external-apis.md](external-apis.md)。
- 產出 [architecture.md](architecture.md)：系統圖、技術選型 Why、Confidence Score／Rating／Cache
  Invalidation／資料同步管線設計、MVP 邊界。
- 產出 [database.md](database.md)：13 張表的欄位、型別、Index 設計與理由，含 Spatial Index 的
  Bounding Box + 精算距離兩段式查詢策略。
- 產出 [api.md](api.md)：端點清單、統一回應格式、查詢參數、Cursor Pagination 決定。

**未完成 / 等待確認：**

- Phase 1（Laravel project initialization）尚未開始，依總 prompt 第 46 節規則，Phase 0 完成後
  需先回報使用者並取得確認才進入下一階段。
- Nominatim 商業使用政策偏保留（見 external-apis.md），若這個專案未來要真的公開營運需要重新評估，
  MVP／Portfolio Demo 範圍內風險視為可接受。

## 2026-08-24 — Phase 1: Laravel Project Initialization

**完成：**

- Laravel 11.56（PHP 8.2）：在乾淨的 `composer:2` Docker container 裡跑 `composer create-project`，
  完全不動主機既有的全域 composer 設定（`~/.composer/config.json` 有 `audit.abandoned=fail` /
  `policy=true`，一開始以為是使用者自訂的全域安全政策，實測後發現連全新的 container 也一樣擋，
  代表這其實是 Composer 對已知安全公告版本的**標準行為**，不是主機客製設定——先跟使用者確認過
  才在**專案層級**的 `composer.json`（`config.policy.advisories.block = false`）加例外，不是改全域）。
- 依賴解析後來又在 `app` container（真正跑 PHP 8.2 的環境）裡重跑一次 `composer update`——
  第一次是用 `composer:2` image 本身的 PHP 8.4 去解依賴，導致 lock file 選到需要 PHP 8.4 的套件版本，
  跟實際跑的 8.2-fpm 對不上，`php artisan migrate` 直接 Fatal error。這是「用哪個 PHP 版本跑
  composer」跟「容器實際跑哪個 PHP」要對齊的坑，記錄下來避免下次再踩。
- Docker：`docker-compose.yml`（app / nginx / mysql / redis）＋ `docker/php/Dockerfile`
  （PHP 8.2-fpm + pdo_mysql/redis/gd/bcmath 等擴充）＋ `docker/nginx/default.conf`。
  MySQL host port 改 3307、nginx host port 改 8080——3306/80 在這台機器上已經被其他專案的
  Docker 佔用，容器內部 port 不動，只調 host 對外映射。
- `.env.example` / `.env`：`DB_*`／`REDIS_*` 指向 docker-compose 服務名稱（`mysql`/`redis`），
  補上 `EXTERNAL_API_*`（Overpass／Nominatim／mock provider 開關，對應 `docs/external-apis.md`）。
- 實測驗證（不是只跑完指令就交差）：
  - `docker compose up -d --build` 四個 container 全部 Running。
  - `php artisan migrate --force`：users/cache/jobs 三張預設表建立成功，證明 MySQL 連線正常。
  - Tinker 裡 `Redis::ping()` 和 `DB::connection()->getPdo()` 都回 OK。
  - `curl http://localhost:8080/` 回 HTTP 200，證明 nginx → php-fpm → Laravel 整條路徑通。

**安全性備註（記錄，非本階段要修）：**

`composer audit` 顯示 laravel/framework 目前有 3 筆公告，其中 `CVE-2026-48019`
（預設 `email` 驗證規則的 CRLF injection）明確涵蓋 Laravel 11.x，官方修法是升級到 12.60+/13.10+。
VeggieMap 的 User/Report 表單都會用到 email 驗證（Phase 4/5），屆時要嘛升級 Laravel 版本、
要嘛在 FormRequest layer 額外處理，不要延到 Phase 13 部署前才想到。

**未完成 / 等待確認：**

- README、`.gitattributes` 等仍是 Laravel 預設內容，尚未寫成 VeggieMap 自己的說明——這是 Phase 11
  的工作，Phase 1 只要求「能跑起來」，先不提前做。
- 尚未安裝 Sail／Horizon／Sanctum 等後續 Phase 才需要的套件，避免這個階段就把依賴裝一堆用不到的。

## 2026-08-24 — Phase 2: Database

**完成：**

- 13 張表的 migration 全部依 `docs/database.md` 實作完成，欄位/型別/index 與文件一致
  （`restaurants.location` 用 `POINT SRID 4326` + Spatial Index，其餘複合 index／unique 均對齊文件）。
- 11 個 Eloquent Model（`Restaurant`／`DietType`／`Feature`／`MenuItem`／`RestaurantVerification`／
  `RestaurantConfidenceScore`／`RestaurantReport`／`Favorite`／`Review`／`ExternalApiLog`，加上
  更新後的 `User`）與對應關聯（`belongsToMany`／`hasMany`／`hasOne`／`belongsTo`）全部建立。
  `restaurants.location` 沒有原生 Eloquent cast，寫入一律走 `DB::raw('ST_SRID(POINT(lng, lat), 4326)')`。
- 10 個 Factory（含台灣城市/行政區的合理經緯度範圍，取代 Faker 預設的全球隨機座標）；
  `diet_types`／`features` 是固定清單，改用 `DietTypeSeeder`／`FeatureSeeder` upsert 而非 factory 隨機產生。
  `RestaurantSeeder` 產 20 家餐廳並掛上 diet types／features／3~8 筆 menu items。
- 實測驗證：`php artisan migrate:fresh --seed --force` 全部 DONE；Tinker 內確認
  `Restaurant::with(['dietTypes','features','menuItems'])` 關聯正確載入、`ST_Distance_Sphere` 半徑查詢
  可正常排序（20 家餐廳、106 筆 menu items）。

**未完成 / 等待確認：**

- `restaurant_confidence_scores` 尚無 seed 資料（設計上由 Phase 6/7 的 `CalculateRestaurantScoreJob`
  批次寫入，不在 Phase 2 範圍內）。
- `composer audit` 的 `CVE-2026-48019`（email 驗證 CRLF injection）仍待處理，同 Phase 1 備註，
  最晚 Phase 4/5 的 User/Report 表單要一併解決。

## 2026-08-24 — Phase 3: API / Repository Layer（餐廳列表＋詳情）

**完成：**

- `routes/api.php` 啟用，`bootstrap/app.php` 加 `api: routes/api.php` + `apiPrefix: 'api/v1'`。
- `App\Exceptions\ApiExceptionRenderer`：統一 `/api/*` 錯誤回應為 `docs/api.md` 的
  `{success:false, error:{code,message}}` 格式（`ValidationException`／`ModelNotFoundException`／
  `NotFoundHttpException`／`AuthenticationException`／`AuthorizationException`／其他 `HttpExceptionInterface`
  各自映射，非 `/api/*` 請求回 `null` 交還預設 handler）。
- `RestaurantRepository::search()`：`docs/database.md` 的半徑搜尋兩段式查詢——
  Bounding Box WKT polygon + `MBRContains` 過濾（`ST_GeomFromText` 先不帶 SRID 算完再
  `ST_SRID(...,4326)` 貼標籤，跟既有 `RestaurantFactory` 寫入 `location` 的手法一致，避開 MySQL 8
  對 SRID 4326 的軸序驗證），再用 `ST_Distance_Sphere` 算精確距離。`distance` 是計算欄位，
  MySQL 不能在同一層 WHERE 引用 SELECT 別名，改用 `fromSub` 包一層讓外層可以對 `distance`
  做 WHERE／ORDER BY／Cursor 分頁比較。支援 `keyword`／`city`／`district`／`diet`／`price_level`／
  `rating_min`／`pet_friendly`／`parking` 篩選與 `distance`／`rating`／`popular`／`newest` 排序。
- `SearchRestaurantRequest`：查詢參數驗證，`sort=distance` 沒帶座標時回 422 而非悄悄退回其他排序。
- `RestaurantResource`／`MenuItemResource`；`RestaurantController@index`／`@show`。
- 實測（真的打 HTTP，不只跑 artisan test）：
  - `GET /api/v1/restaurants` 列表、`?latitude=&longitude=&radius=` 半徑搜尋（距離遞增排序正確）、
    Cursor 分頁真的翻到第二頁且無重複/漏筆、`sort=rating`、`?diet=vegan` 篩選皆正常。
  - `GET /api/v1/restaurants/{id}` 詳情頁正確帶出 `dietTypes`／`features`／`menuItems`。
  - 404（`ModelNotFoundException`）與 422（自訂驗證錯誤）都回文件要求的錯誤格式。
  - `EXPLAIN` 確認 `restaurants_location_spatial` 出現在 `possible_keys`（predicate 寫法可用該索引）；
    但目前只有 20 筆種子資料，optimizer 判斷整表掃描比走索引便宜而選擇 `type=ALL`——這是 MySQL
    對小表的正常決策，不是查詢寫錯，資料量大了之後才有意義重新驗證是否真的走索引。

**未完成 / 等待確認：**

- `open_now` 篩選參數目前**沒有實作**——schema 裡沒有任何營業時間欄位/表，`docs/database.md`
  也未設計。要嘛之後補 `opening_hours` 相關表，要嘛從 `docs/api.md` 拿掉這個參數，先回報不擅自二選一。
- `/diets`、`/features`、收藏／評論／回報／auth（Sanctum 尚未安裝）等端點仍未實作，留給後續 Phase。
- `RestaurantController`／`RestaurantRepository` 目前沒有自動化測試（`tests/Feature`），
  這次驗證全靠手動 curl／tinker／EXPLAIN，之後要補 Feature test 固化這些行為。

## 2026-08-24 — Phase 3 續：`/diets`、`/features`

**完成：**

- `open_now` 決議：先擱置、不動 schema，`docs/api.md` 參數列表照舊保留，等未來真的要做
  營業時間才回來補 `opening_hours` 表與篩選邏輯。
- `GET /api/v1/diets`、`GET /api/v1/features`：固定清單（各 7／8 筆），資料量小不分頁，
  共用一個 `LookupController`（`dietTypes()`／`features()`）而非各開一個 controller。
  `DietTypeResource`／`FeatureResource` 只回 `code`／`label`。
- 實測：兩支端點都打過 HTTP，回傳筆數與內容跟 `DietTypeSeeder`／`FeatureSeeder` 的固定清單一致。

**未完成 / 等待確認：**

- 收藏／評論／回報／auth（Sanctum 尚未安裝）、Feature test 仍未動工，留給下一階段。

## 2026-08-24 — Phase 4: Auth（Sanctum）＋ 收藏

**完成：**

- `composer require laravel/sanctum`：在 `app` container（PHP 8.2，跟 Phase 1 記錄的坑一致，
  沒有用 composer:2 image 本身的 PHP 8.4 去解依賴）安裝，`personal_access_tokens` migration
  已跑。`User` model 加 `HasApiTokens` trait。
- `AuthController`：`POST /auth/register`（`RegisterRequest` 驗證，`password confirmed`）／
  `POST /auth/login`（`Hash::check` 失敗回 `ValidationException`，不洩漏帳號是否存在）／
  `POST /auth/logout`（撤銷當前 token，`auth:sanctum` 保護）。純 Bearer token 認證，
  沒有用 Sanctum 的 SPA cookie/stateful 模式（這個專案是 API-only，不需要）。
- `MeController@show`（`GET /me`）、`FavoriteController`（`GET /me/favorites` cursor 分頁列表、
  `POST`／`DELETE /restaurants/{id}/favorite`）。收藏加入用 `firstOrCreate` 冪等；
  取消收藏對本來就沒收藏的餐廳也回成功（冪等，見程式碼註解），不特別開 `FavoritePolicy`——
  這個動作除了「已登入」以外沒有其他授權判斷維度，`docs/api.md` 講的 Policy 針對的是
  Review／Report／Restaurant 那種有 ownership／審核角色的資源。
- 實測全流程（真打 HTTP）：register → 重複 email 422 → login 錯密碼 422 → login 成功 →
  無 token 打 `/me` → favorite → 重複 favorite 冪等 → `/me/favorites` 有資料 → unfavorite →
  `/me/favorites` 清空 → logout → 用同一個 token 再打 `/me` 確認 401（token 真的被撤銷）。

**過程中抓到並修掉 2 個真的 bug（不是憑空猜的）：**

1. `/me` 沒帶 token 原本回 **500**（`Route [login] not defined.`），不是文件要求的 401。
   原因：Laravel 預設 `Authenticate` middleware 在請求沒有明確 `Accept: application/json` header
   時，`redirectTo()` 會嘗試 `route('login')`——這個純 API 專案根本沒有 `login` 具名路由，
   丟出的 `RouteNotFoundException` 蓋掉了原本該回的 `AuthenticationException`。
   修法：`bootstrap/app.php` 用 `$middleware->redirectGuestsTo(fn () => null)` 強制永不重導。
2. 註冊回應裡 `user.role` 原本是 `null`，但 DB 實際存的是 migration default 的 `user`
   （`Eloquent::create()` 後記憶體 model 不會自動回填 DB-side default）。修法：
   `AuthController::register()` 顯式帶 `'role' => 'user'`，不依賴 DB default 反映到回應。

**未完成 / 等待確認：**

- 評論／回報端點、`FormRequest`／`Policy`（`ReviewPolicy`／`ReportPolicy`）仍未實作。
- `AuthController`／`FavoriteController` 沒有自動化測試，跟 Phase 3 一樣先靠手動驗證，
  之後要補 Feature test。
- Token 沒有 expiry／refresh 機制，`config/sanctum.php` 目前是預設值（`expiration => null`
  永不過期）——MVP／Portfolio Demo 範圍可接受，正式營運要重新評估。

## 2026-08-24 — Phase 5: 評論／回報

**完成：**

- `ReviewService::submit()`：`reviews` 的「同一使用者對同一餐廳只能有一筆 active review」
  無法用 DB unique constraint 表達，改用交易——把現有 active review 改成 hidden 再建立新的，
  等於「重新評論＝覆蓋上一筆」並保留歷史。併發安全靠 InnoDB REPEATABLE READ 對
  `(user_id, restaurant_id, status)` 索引範圍的隱含 next-key lock，不需要額外顯式鎖。
- `GET /restaurants/{id}/reviews`（無需登入，只顯示 `status=active`，cursor 分頁）／
  `POST /restaurants/{id}/reviews`（`CreateReviewRequest` 驗證 rating 1~5）。
- `POST /restaurants/{id}/reports`（`CreateRestaurantReportRequest` 驗證 `type` 是文件列的
  7 種 enum 值之一）。
- `ReviewPolicy`／`RestaurantReportPolicy`（`create()` 目前都只要求已登入，沒有其他授權維度——
  審核／擁有者專屬的判斷留到有對應端點時再加）；`app/Http/Controllers/Controller` 補上
  `AuthorizesRequests` trait（Laravel 11 預設骨架的空 base controller 沒有這個，`$this->authorize()`
  要靠它才能用）。
- 實測全流程（真打 HTTP）：未登入看評論列表（空）→ 無 token 寫評論 401 → rating 超出範圍 422 →
  第一次評論成功 → 同使用者對同餐廳第二次評論 → 列表只剩最新一筆 active（舊的變 hidden，
  驗證覆蓋邏輯真的生效，不是憑程式碼讀出來的推測）→ 回報成功 → 回報 `type` 不合法 422 →
  無 token 回報 401。

**過程中抓到並修掉 1 個真的 bug：**

`POST /restaurants/{id}/reports` 一開始固定回 403「This action is unauthorized.」，即使
`RestaurantReportController` 明確呼叫了 `$this->authorize('create', RestaurantReport::class)`
且對應 Policy 的 `create()` 寫死回 `true`。根因：Laravel 的 Policy 自動發現慣例是
`{Model 類別名稱}Policy`——`App\Models\RestaurantReport` 對應的是 `App\Policies\RestaurantReportPolicy`，
不是我原本取名的 `ReportPolicy`，命名對不上，Laravel 找不到 Policy 時預設視為未授權。
改檔名／類別名稱成 `RestaurantReportPolicy` 後重測通過。

**未完成 / 等待確認：**

- Review／Report 都還沒有 Feature test，繼續靠手動 curl 驗證，之後要補。
- `restaurant.rating`／`rating_count` 沒有在寫入 review 時同步更新——依 `docs/database.md` 設計，
  這是快取欄位，由未來 Phase 6/7 的 `RecalculateRestaurantRatingJob` 批次更新，Phase 5 範圍
  只負責把 review 寫進去。
- Admin 審核 report（approve/reject、`reviewed_by`／`reviewed_at`）尚未實作，`docs/api.md`
  端點清單本來就沒列出對應 API，留給未來若有 Admin 面板時再處理。

## 2026-08-24 — Feature Test 補完

**完成：**

- 獨立測試資料庫 `veggiemap_testing`（同一個 Docker MySQL container，不碰 `veggiemap` 開發資料）：
  `CREATE DATABASE` + `GRANT ALL` 給既有 `veggiemap` 使用者。schema 用了 MySQL 專屬空間函式
  （`POINT`／`ST_Distance_Sphere`／`MBRContains`，見 `RestaurantRepository`），sqlite 跑不起來，
  所以沒有走常見的「測試用 sqlite in-memory」路線。`phpunit.xml` 直接把 `DB_*` env 硬指到這個庫，
  `RefreshDatabase` 每個測試方法都在交易裡跑完就 rollback。**這是手動一次性設定，新環境／CI
  要跑這個測試套件前得先手動建這個庫**（沒有寫成 migration/setup script，見「未完成」）。
- 6 個 Feature test 檔案（`tests/Feature/Api/`）涵蓋目前所有端點：`RestaurantTest`（列表／篩選／
  半徑搜尋距離排序／cursor 分頁翻頁不重複不漏／詳情／404）、`LookupTest`、`AuthTest`（含
  Phase 4 抓到的 role null／guest 401 兩個 regression）、`FavoriteTest`（含冪等行為）、
  `ReviewTest`（含覆蓋舊評論邏輯）、`RestaurantReportTest`（含 Phase 5 抓到的 Policy 命名
  regression）。共 30 個測試、82 個 assertion，全綠。
- 寫測試過程中兩個一開始失敗的案例，深入排查後確認**都是測試假象、不是產品 bug**：
  1. `logout` 測試：同一個 PHPUnit test method 內連續打兩次 HTTP 請求，共用同一個 app 容器，
     Sanctum 的 `RequestGuard` 會把第一次解析出的 user 快取在物件屬性上，不會因為 DB 裡的
     token 被刪就重新查——這只在單一 process 的測試環境發生（真實環境每個請求是獨立
     PHP-FPM process，Phase 4 手動 curl 已經驗證過 logout 後續請求真的會 401）。修法：
     兩次請求之間呼叫 `app('auth')->forgetGuards()` 清快取，不是改程式碼。
  2. `distance_meters` 型別斷言：JSON 編碼把整數值的浮點數（例如 `30.0`）序列化成 `30`，
     `json_decode` 讀回來是 PHP int——這是 JSON 編碼慣例，不是資料算錯。斷言改用
     `assertIsNumeric` 而非 `assertIsFloat`。

**未完成 / 等待確認：**

- 建測試庫是手動下的 SQL，沒有寫成腳本／文件化到 README（README 本身還是 Laravel 預設內容，
  留給 Phase 11）。之後要接 CI 的話，這個「先手動建 `veggiemap_testing`」的步驟必須自動化，
  否則 CI 環境跑不起來。
- 只覆蓋 Feature/HTTP 層行為，沒有 Unit test 覆蓋 `RestaurantRepository::boundingBoxPolygon()`
  這種純計算邏輯，也沒有測試 `ReviewService` 併發競態（需要真的並行請求才能驗證 next-key
  lock 有沒有效，目前只驗證了「循序覆蓋」邏輯）。

## 2026-08-24 — Phase 6: Rating／Confidence Score 批次計算 Job

**完成：**

- `config/vegetarian.php`：`verification_weights`（`restaurant_claim`／`menu_verified`／
  `user_report`／`photo_verified`／`external_source`／`admin_verified` 六種驗證類型的分數），
  `docs/database.md` 明確要求 `restaurant_verifications.score` 要對應這份 config，不寫死在程式碼。
- `VerificationService::record()`：集中管理「寫入一筆驗證紀錄時分數怎麼決定」的邏輯（查 config），
  供未來寫驗證紀錄的呼叫端共用。**目前還沒有任何 HTTP 端點會呼叫它**——餐廳自主認領／Admin
  後台／Phase 8 的 `restaurants:sync` 外部資料匯入都還沒實作，先把邏輯定義好等之後接。
- `RecalculateRestaurantRatingJob`：算某餐廳所有 `status=active` 的 review 平均分數與筆數，
  更新 `restaurants.rating`／`rating_count` 快取欄位。掛在 `ReviewService::submit()` 尾端，
  每次評論異動（新增或覆蓋）後觸發。
- `CalculateRestaurantScoreJob`：加總某餐廳所有未過期（`expires_at is null or > now()`）的
  `restaurant_verifications.score`，封頂 100，upsert 進 `restaurant_confidence_scores`。
- 兩支 Artisan 批次指令：`restaurants:recalculate-ratings`／`restaurants:calculate-scores`，
  chunk 過全部餐廳逐一 dispatch，用於 backfill／之後接 cron。
- **關鍵設計決定（不是隨口做的）**：兩個 Job 都實作 `ShouldQueue`，但呼叫端一律用
  `dispatchSync()` 而不是 `dispatch()`。原因：這個專案目前沒有跑 queue worker（`QUEUE_CONNECTION=redis`，
  Phase 1 就記錄過 Horizon/Sail 之類的套件還沒裝），如果真的呼叫 `dispatch()` 丟進 Redis 佇列，
  沒有 worker 消化的話 rating／confidence score 就永遠不會更新——會變成一個「程式碼看起來
  對、實際上什麼都沒發生」的死路徑。等之後真的架了 queue worker，把 `dispatchSync` 改回
  `dispatch` 即可，Job 類別本身不用動。
- 實測：
  - 6 個新 Feature test（`RecalculateRestaurantRatingJobTest`／`CalculateRestaurantScoreJobTest`）：
    hidden review 不計入平均、零 active review 歸零、API 送出評論後 rating 真的更新、
    過期驗證不計分、加總封頂 100、`VerificationService` 正確查表。全部通過（連同既有測試共
    36 個、92 個 assertion）。
  - 在**非測試的 dev 資料庫**上也真打過一次 HTTP（送出評論後 `GET /restaurants/1` 的 rating
    立即反映）＋跑過兩支 artisan 指令（`restaurants_confidence_scores` 表 20 筆全部 upsert 成功），
    確認 `dispatchSync` 這個決定在真實環境（不只測試環境的 sync queue driver）真的有效。

**未完成 / 等待確認：**

- 沒有任何端點會真的建立 `restaurant_verifications` 紀錄，所以目前所有餐廳的 confidence score
  都是 0——這不是 bug，是「分數計算管線已經打通，但分數的資料來源（自主認領／Admin／
  外部匯入）還沒做」的誠實現況，等 Phase 8（`restaurants:sync`）或 Admin 功能接上才會有非零值。
- 沒有排程（`routes/console.php` 的 schedule）自動跑這兩支批次指令，目前只能手動執行。

**追加（同一輪，使用者已授權後續建議自動採用）：**

`docs/api.md` 端點清單當初沒列 `confidence_score`，是文件遺漏——已經補進
`RestaurantResource`（`GET /restaurants/{id}` 詳情頁 eager load `confidenceScore` 關聯；列表頁
沒有帶，避免每筆多一次額外查詢的成本，且列表頁场景用不太到這個欄位）。測試與真實 HTTP
都驗證過會正確回傳（沒有分數紀錄時是 `null`，跑過批次指令後是實際數字）。

## 2026-08-24 — Phase 8: `restaurants:sync` 外部資料匯入

**完成：**

- Adapter Pattern（`docs/architecture.md`）：`RestaurantProviderInterface` + `RestaurantData`／
  `BoundingBox` value object，`OsmRestaurantProvider`（真的呼叫 Overpass API，30s timeout、
  429 退避重試、寫 `ExternalApiLog`）／`MockRestaurantProvider`（讀
  `storage/app/mock/restaurants.json` fixture，Overpass 斷線或本機無網路時的保底）。
  `AppServiceProvider` 依 `EXTERNAL_API_RESTAURANT_PROVIDER`（預設 `mock`，避免開發/測試環境
  不小心打到真的 Overpass）綁定介面。
- `RestaurantSyncService`：對每筆匯入資料——用 `(source, source_id)` upsert（重跑同一批不會
  產生重複列，已實測驗證冪等）、掛 diet_types、「同名＋距離 <100m 視為可能重複」兩筆都標記
  `is_possible_duplicate`（不自動合併/刪除，見 `docs/database.md`）、透過 `VerificationService`
  建立 `external_source` 驗證紀錄、`dispatchSync` 觸發 `CalculateRestaurantScoreJob`——這是
  Phase 6 那條「confidence score 目前全部是 0，因為沒有資料源會寫入 verification」的缺口，
  Phase 8 補上後分數管線才真正跑得起來。
- `php artisan restaurants:sync --bbox=minLat,minLng,maxLat,maxLng [--provider=mock|osm]`：
  `--bbox` 必填（不給明確錯誤訊息，不讓人一次不小心撈全台灣），`--provider` 可覆蓋 config
  做單次測試。
- 5 筆 fixture 資料（`storage/app/mock/restaurants.json`，台北/台中/高雄各一到三家，含
  `diet_codes`），讓 mock provider 開箱即可匯入非空結果。
- 實測（真打 command，非只讀程式碼）：
  - mock provider 匯入 5 筆成功，diet_types／verification／confidence score 全部正確寫入
    （用 `mysql --default-character-set=utf8mb4` 直接查表驗證，中途發現 client 預設連線
    charset 沒設會讓中文查詢條件對不上，這是查證方式的坑不是資料的坑）。
  - 冪等性：同一批 fixture 再跑一次，`created=0／updated=5`，`restaurants` 總數沒有變多。
  - Dedup：手動塞一筆跟既有餐廳同名同座標（不同 `source_id`）的資料，兩筆都被標記
    `is_possible_duplicate=1`；同名但距離遠（跨城市）的則不會誤標。
  - 7 個新 Feature test（`RestaurantSyncServiceTest`，含用匿名類別實作
    `RestaurantProviderInterface` 做的單元化測試，不依賴共用 fixture 檔內容），加上既有測試
    共 43 個、108 個 assertion，全綠。

**過程中抓到並修掉 1 個真的 bug：**

`Str::slug()` 對純中文名稱（例如「清心蔬食」）音譯不出任何 ASCII 字元，回傳空字串——實測
匯入後所有中文餐廳名的 slug 全部撞成同一個 fallback（`restaurant-2`／`restaurant-3`…），
`docs/database.md` 講的「slug 供人類看得懂的 URL 用」的設計目的完全失效。這對台灣餐廳平台
是會影響所有資料的系統性問題，不是邊角案例。修法：轉不出 ASCII 字元時退回用
`{來源}-{來源ID}`（例如 `osm-mock-node-1001`）當種子，每家餐廳至少有不同、可追溯的 slug。
寫了對應 regression test（`slug_falls_back_to_source_seed_...`）。

**未完成 / 等待確認：**

- `NominatimGeocodingProvider`（使用者輸入地址轉經緯度）**沒有實作**——`docs/api.md` 的端點
  清單裡本來就沒有「地址搜尋」這條 API（現有 `GET /restaurants` 直接吃 `latitude`/`longitude`
  數字），這部分屬於範圍外，不是漏做。
- Circuit breaker（`docs/external-apis.md` 提到「連續 N 次失敗後停止」）目前沒有實作：一次
  `restaurants:sync` 呼叫只打一次 Overpass API（一個 bounding box = 一次請求），單次呼叫內
  沒有「連續失敗」的情境可以觸發斷路器。這個概念要等未來做「一次排程掃多個 bounding box」
  的批次排程器時才有意義，先誠實記下不是忘記做。
- 沒有排程自動跑 `restaurants:sync`，目前只能手動執行（跟 Phase 6 的批次計算指令一樣）。
- `restaurants:sync --provider=osm` 沒有打過真的 Overpass API 驗證（只測過 mock provider）——
  `OsmRestaurantProvider` 的 HTTP 呼叫／重試／`ExternalApiLog` 寫入邏輯目前只靠程式碼審視，
  沒有實際對外流量驗證過，之後要跑一次真的匯入才能確認。

## 2026-08-24 — Phase 7: Admin 審核（report／review）

**範圍先講清楚：`docs/api.md` 的端點清單原本完全沒列 Admin 相關端點**，這個 Phase 是我自己
依 `restaurant_reports`／`reviews` 表已經有的 `reviewed_by`／`reviewed_at`／`status` 欄位
（見 `docs/database.md`）反推設計出來的，不是照抄某份既有規格，設計決定列在下面。

**完成：**

- `RestaurantReportPolicy::review()`／`ReviewPolicy::moderate()`：`$user->isAdmin()` 判斷，
  非 admin 一律 403（沿用既有的 Policy 自動發現機制與 `ApiExceptionRenderer` 錯誤格式）。
- `GET /api/v1/admin/reports`（預設只列 `pending`，可用 `?status=` 切換）／
  `POST /api/v1/admin/reports/{id}/approve`／`POST /api/v1/admin/reports/{id}/reject`：
  只更新這筆回報自己的 `status`／`reviewed_by`／`reviewed_at`，**刻意不會**反過來自動改動
  被回報的餐廳資料（例如 `type=closed` 不會自動把 `restaurant.status` 改成 `inactive`）——
  文件沒定義這種連動規則，寧可讓 Admin 自己另外處理，也不要憑空猜一個沒人要求的自動化行為。
  重複審核已審核過的回報回 422，不會覆蓋歷史。
- `GET /api/v1/admin/reviews`（可用 `?status=`／`?restaurant_id=` 篩選，看得到 `hidden` 的）／
  `POST /api/v1/admin/reviews/{id}/hide`：隱藏後立刻 `dispatchSync(RecalculateRestaurantRatingJob)`，
  跟 `ReviewService::submit()` 用同一套「沒有 queue worker，用 dispatchSync 保證真的生效」的邏輯。
  重複隱藏已隱藏的評論回 422。
- 實測（真打 HTTP，非 admin／admin 兩種身分都測過）：
  - 建了一個真實帳號、手動在 DB 把 `role` 改成 `admin`（沒有 Admin 帳號建立/晉升的 API，
    這本來就不在任何文件範圍內，MVP 靠手動 SQL 是合理的）。
  - 非 admin 打 `/admin/reports`／approve／`/admin/reviews`／hide 全部正確 403。
  - Admin 建立→列出→approve→再 approve 一次（422，不會覆蓋）全程正確；`reviewed_by` 正確記成
    審核者的 user id。
  - 送出評論（rating 從 0/0 變 1/1）→ Admin 隱藏該評論 → `GET /restaurants/{id}` 的 rating
    立刻掉回 0/0，證明 `RecalculateRestaurantRatingJob` 真的被觸發且拿到正確的最新資料。
  - 11 個新 Feature test（`tests/Feature/Api/Admin/`），加上既有測試共 54 個、129 個
    assertion，全綠。

**未完成 / 等待確認：**

- 沒有「Admin 帳號建立／晉升」的 API 或指令——目前只能手動改 DB。這對正式營運不可接受，
  但 MVP／Portfolio Demo 範圍先不做，之後若要做，合理的形式會是一支 Artisan command
  （例如 `users:promote {email}`）而不是公開 API。
- `restaurant_reports` 的審核結果目前完全不影響被回報的餐廳本身（見上面設計決定）——如果
  之後要接「approve 後自動處理餐廳資料」的規則，需要先定義清楚每種 `type` 該對應什麼動作，
  不是這個 Phase 該猜的範圍。
- Admin review 列表沒有像 `/restaurants` 那樣支援 `sort`／`keyword`，只有基本狀態/餐廳 id
  篩選——Admin 審核佇列的資料量級跟公開列表不同，先做最小可用版本。

## 現況總覽（2026-08-24 收尾）

（此段為 2026-08-24 收尾當下的舊快照，Phase 8.5／9 完成後現況見下方各自的 Phase 記錄與
[todo.md](todo.md)）Phase 0～8 全部完成並實測：架構／文件、Laravel＋Docker 專案初始化、13 張表
migration＋Model＋Factory＋Seeder、`/restaurants`（含半徑搜尋兩段式查詢＋cursor 分頁）、
`/diets`／`/features`、Sanctum 認證＋收藏、評論／回報、Rating／Confidence Score 批次計算 Job、
`restaurants:sync` 外部資料匯入（Overpass／Mock Provider）、Admin 審核 report／review。
54 個 Feature test、129 個 assertion，全綠。

## 2026-08-24 — Phase 8.5：地址搜尋（Geocoding）

**完成：**

- `GeocodingProviderInterface` + `GeocodedPlace` value object（`app/Services/External/`），
  跟 Phase 8 的 `RestaurantProviderInterface` 同一套 Adapter Pattern。只有 Nominatim 一種
  provider（`docs/external-apis.md` 已核准，沒有 mock/real 切換需求），直接在
  `AppServiceProvider` 綁死 `NominatimGeocodingProvider`，不做用不到的抽象。
- `NominatimGeocodingProvider`：呼叫 Nominatim `/search`，5s timeout、429 退避重試（`throw:false`，
  非 429 的失敗回應正常回傳而不是被 Laravel HTTP client 的 retry 機制強制丟例外）、寫
  `ExternalApiLog`。
- `GET /api/v1/geocode?q=關鍵字`：`GeocodeRequest` 驗證、`Cache::remember('geocode:'.md5($q), 1天, ...)`
  擋重複查詢字串，避免撞 Nominatim 每秒 1 次請求的政策限制。失敗時回
  `{"success":true,"data":[]}`，不讓地圖首頁因外部服務掛掉而整個壞掉。
- 4 個 Feature test（`tests/Feature/Api/GeocodeTest.php`，`Http::fake` 模擬 Nominatim，不真打
  外部 API）：正常回傳、缺 `q` 回 422、Nominatim 失敗回空陣列且正確記 `error_code`、重複查詢
  真的只打一次 Nominatim（cache 生效）。加上既有測試共 58 個、141 個 assertion，全綠。

**過程中抓到並修掉 1 個真的 bug：**

`->retry($times, $sleep, $when)` 的第 4 個參數 `$throw` 預設是 `true`——代表即使 `$when`
判斷不該重試（例如收到 503 而非 429），Laravel HTTP client 還是會在耗盡重試次數後把
非 2xx 回應強制轉成例外丟出來。寫 Feature test 模擬 503 時才發現：預期回應該正常帶
`error_code=HTTP_503`，實際卻是被外層 `catch (\Throwable)` 接住、記成籠統的
`error_code=RequestException`，功能上仍會 fallback 成功但記錄不準確。修法：`retry()` 加
`throw: false`，只在真的觸發 429 重試時才允許中途丟例外驅動 retry 迴圈本身，其他失敗回應
正常回傳讓程式自己判斷 `successful()`。

**實測（真打外部 Nominatim API，非 mock）：**

打 `GET /api/v1/geocode?q=台中一中街` 一開始回空陣列，查 `external_api_logs` 發現
`status=403`——不是程式碼邏輯錯，是 `.env` 的 `EXTERNAL_API_NOMINATIM_USER_AGENT` 預設值
`"VeggieMap/1.0 (contact: you@example.com)"` 帶著 `example.com` 這種常見教學範例網域字串，
被 Nominatim 的防護機制直接擋掉（用 `curl` 直接對 Nominatim 測試同一組 User-Agent 字串重現，
排除是本地網路或程式碼問題）。改成 `VeggieMap/1.0 (+https://github.com/thothawei/veggie-map)`
後重打，成功拿到「台中一中, 育才街...」等真實結果，`external_api_logs` 記到 `status=200`。
細節見 [api.md](api.md) 的「踩過的坑」段落。

**未完成 / 等待確認：**

- `docs/openapi.yaml` 仍未產出（Phase 11 範圍），`/geocode` 先只記在 `docs/api.md`。
- 前端串接（輸入框 → call `/geocode` → 移動地圖）留給 Phase 9，這個 Phase 只做後端 API。

## 2026-08-24 — Phase 9：Vue 3 + Leaflet 前端

**完成：**

- `npm install` 加 `vue`／`vue-router@4`／`pinia`／`leaflet`／`leaflet.markercluster`
  （`vue-router@5` 需要 vite 7/8，跟現有 vite 6 衝突，鎖 4.x）、`typescript`／`vue-tsc`／
  `@vitejs/plugin-vue`／`@types/leaflet`（`typescript@7` 是最新版但 `vue-tsc@3.3.11` 還不支援
  它移除 `./lib/tsc` export 的新版佈局，鎖回穩定的 `typescript@5.9.3`）。
- 架構決定：SPA 不是獨立跑在 5173 的專案，而是延續 Laravel 既有的 `laravel-vite-plugin`
  整合方式——`resources/views/app.blade.php` 當 shell，`routes/web.php` 用
  `Route::view('/{any}', 'app')->where('any', '.*')` 把所有非 `/api`、非 `/up` 的路徑交給
  Vue Router（history 模式）自己決定畫面。`npm run dev` 只負責資產編譯與 HMR，實際頁面
  一律從 `http://localhost:8080/` 進，不是 5173——比總 prompt 原本設想的「前端獨立跑在
  5173」更貼近這個專案已經是 Laravel 全端專案的事實，也不需要另外處理 CORS 讓頁面本身跨
  origin（`config/cors.php` 還是加了，給 API 呼叫本身用，也方便未來真的要拆前後端時直接用）。
- 頁面：`HomeView`（地圖首頁）、`RestaurantListView`、`RestaurantDetailView`、`LoginView`、
  `RegisterView`、`FavoritesView`、`ProfileView`、`AdminView`（reports/reviews 審核）。
  路由用 `:id` 不是總 prompt 寫的 `:slug`——後端 `GET /restaurants/{restaurant}` 是 id-based
  route model binding（`docs/api.md` 早就這樣記錄），沒有 slug 查詢能力，前端路徑照實際 API
  能力設計，不假裝支援不存在的查詢方式。
- `RestaurantMap.vue`：Leaflet + `leaflet.markercluster`，`moveend` 事件驅動依 bounds 撈餐廳
  （`HomeView` 用 bounds 中心點＋對角線距離的一半當半徑，直接餵給既有的 `/restaurants` 半徑
  搜尋，不用另開一支「依 bounds 查」的 API）、目前位置（`navigator.geolocation`）、marker
  popup、點擊導到詳情頁。
- `SearchBox.vue`：串 Phase 8.5 的 `/geocode`，選取候選地點後地圖 `flyTo`。
- `FilterDrawer.vue`：串 `/diets`／`/features`，`defineModel` 雙向綁定篩選條件。
- Pinia：`stores/auth.ts`（token 存 localStorage、`fetchCurrentUser`／`login`／`register`／
  `logout`）、`stores/favorites.ts`。Axios client（`api/client.ts`）攔截器自動帶 Bearer token。
- Router guard：`meta.requiresAuth`／`requiresAdmin` 未登入導去 `/login?redirect=`。

**過程中抓到並修掉的問題：**

1. `vue-tsc --noEmit` 直接崩潰（`ERR_PACKAGE_PATH_NOT_EXPORTED`）——根因是 npm 預設裝到最新的
   `typescript@7.x`，這個專案跑的當下 `vue-tsc` 生態還沒跟上 TS7 的新 export 佈局，不是
   我的程式碼問題。鎖回 `typescript@^5.9` 解決。
2. `body` 沒有明確設 `background`，在瀏覽器實測時整頁背景是黑的（繼承宿主環境的深色主題），
   不是 CSS 邏輯錯誤但會在不同瀏覽器/系統主題下表現不一致——加上明確的 `background: #fff`。
3. Template 裡 `@blur="() => setTimeout(...)"` 型別檢查不過（Vue 元件實例代理沒有全域
   `setTimeout`），改成具名方法呼叫 `window.setTimeout`。

**實測（真的開瀏覽器點，不只 build 過）：**

`npm run build`（含 `vue-tsc --noEmit`）過、`npm run dev` 起 Vite，瀏覽器開
`http://localhost:8080/` 逐條走過：地圖載入台中市 20 家種子餐廳並正確分群成 cluster、點開
cluster 看到個別 marker、點 marker 跳轉到 `/restaurants/{id}` 詳情頁且內容（名稱/評分/地址/
菜單/diet標籤/features標籤）正確；搜尋框輸入「台北車站」→ 真的打 Nominatim 拿到候選清單 →
點選後地圖飛到台北（附近沒有種子餐廳，正確顯示空結果，不是 bug）；註冊帳號 → nav 正確切換
成已登入狀態 → 進入某餐廳詳情頁按「加入收藏」即時變成「已收藏」→ 送出評論後評分立即從
0.0(0) 變成 5.0(1)，證明前端打的 API 跟 `RecalculateRestaurantRatingJob` 的
`dispatchSync` 串起來了 → `/favorites` 頁面正確列出剛收藏的餐廳。全程瀏覽器 console 無錯誤。
`/restaurants` 列表頁篩選 chip 與搜尋框也正常。

**未完成 / 等待確認：**

- `AdminView` 只用程式碼推導 admin API 呼叫方式驗證過（unit 層級沒問題），沒有實際拿一個
  admin 角色帳號在瀏覽器裡點過核准/駁回/隱藏——這個決定是因為要手動改 DB 把某帳號設
  `role=admin` 才能測，範圍上跟 Phase 7 當初手動測試 admin API 的方式一致，但沒有在這次
  一併重新過一次瀏覽器驗證，下次有動 Admin 相關程式碼時要記得補測。
- 沒有 Vitest／Playwright 這類前端自動化測試，目前全靠這次的手動瀏覽器驗證，見
  `docs/todo.md` Phase 10。
- Mobile responsive（總 prompt 要求的地圖 UX 項目之一）目前只用桌面尺寸驗證過，沒有實際
  切到手機視窗檢查版面。
- Router guard 的 `requiresAdmin` 判斷依賴 `auth.user` 已經載入完成；如果使用者帶著舊
  token 直接刷新進 `/admin`，`fetchCurrentUser()` 還沒 resolve 前那一瞬間會先被導回首頁，
  是已知的競態限制，MVP 範圍先不特別處理（重新整理後手動點一次連結即可正常進入）。

## 2026-08-24 — Phase 10：補測試缺口

**完成：**

- `tests/Unit/RestaurantRepositoryBoundingBoxTest.php`：用 `ReflectionMethod` 直接測
  `boundingBoxPolygon()`（private 純數學方法，不碰 DB，用純 `PHPUnit\Framework\TestCase`
  不啟動 Laravel framework）。4 個測試：WKT 封閉環格式、赤道附近的角座標數值、高緯度經度
  跨幅確實變寬（地球是球體不是平面格線）、極點附近除以零防呆真的不會出 NAN/INF。
  自我驗證過：故意把 `lngDelta` 改錯（等於 `latDelta`，忽略緯度修正）重跑，
  「高緯度經度跨幅變寬」那條測試真的紅了，確認測試有真的在測東西，不是恆真斷言。
- `docs/todo.md` 原本列的「Unit test：距離計算相關純邏輯」查證後發現不存在對應目標——
  這個專案的距離計算 100% 在 SQL 端（`ST_Distance_Sphere`），沒有獨立的 PHP 距離公式可以
  抽出來單元測試，唯一的純 PHP 幾何邏輯就是上面測掉的 bounding box。誠實記錄查證結果，
  不硬湊一個沒意義的測試。
- `scripts/setup-test-db.sh` + `docker/mysql/init/01-create-test-database.sql`：把
  「Feature Test 補完」那次手動下的 SQL 腳本化。全新 volume 靠後者（MySQL image 只在
  volume 第一次初始化時執行 `docker-entrypoint-initdb.d`），已存在的 volume（多數本機
  開發環境）不會自動重跑，所以前者是隨時可以重跑的等效版本，也是未來 Phase 12 CI 要用的
  那一步。實測：對已經手動建過的既有測試庫重跑，`CREATE DATABASE IF NOT EXISTS` 正確
  no-op、`migrate --force` 正確回報 `Nothing to migrate`。
- `tests/Feature/ReviewServiceConcurrencyTest.php` + `tests/Support/hold_review_lock.php`：
  第一個真正讓兩個交易重疊的測試（Phase 5 的 Feature Test 補完只驗證過循序覆蓋）。
  背景用獨立 PHP process（`Illuminate\Support\Facades\Process`）+ 原生 PDO（不能用
  `RefreshDatabase`——它把整個測試包在一個交易裡，另一條連線看不到未 commit 的資料）
  故意撐住鎖，前景呼叫真正的 `ReviewService::submit()`，斷言：(1) 前景真的被鎖卡住等待
  （測 elapsed time，不是只看結果）、(2) 併發下仍然只有一筆 active review。
- **這個測試在開發過程中抓到一個真的 bug**：`ReviewService::submit()` 原本的程式碼註解
  宣稱靠 InnoDB 的 next-key lock 就能保證併發安全，但實測發現兩個交易對同一個空 index
  range 各自取得 gap lock 後都想 `INSERT` 進那個 gap 時，InnoDB 會判定成 deadlock
  （`1213 Deadlock found`）直接丟例外中止其中一個交易——不是誰乖乖排隊等誰。原本的
  `DB::transaction($fn)` 沒有帶重試次數（預設 1 次），代表使用者端在真實併發下有機會
  收到 500 而不是優雅地稍等一下。修法：`DB::transaction($fn, 3)`，帶 Laravel 內建的
  deadlock 自動重試。
- **測試本身也做了自我驗證，且過程中發現结果比預期複雜**：拔掉 `, 3` 重跑 8 次，
  沒有一次重現 deadlock 例外——因為背景測試腳本自己也有重試迴圈（一開始加是為了讓
  背景 process 不要一遇到 deadlock 就整個腳本崩潰），會默默吸收掉大部分的 deadlock，
  使得這個測試對「前景是否有重試」這件事本身不是每次都能觸發判定。誠實記錄：這個測試
  可靠驗證的是「兩個真的重疊的交易之後，資料庫裡永遠不會出現兩筆同時 active 的 review」
  這個核心不變量（每次都驗證到），但不是每次都能重現最一開始抓到的那個 deadlock
  interleaving 本身——那個 bug 是靠人工重跑同一個測試多次、配合關掉背景腳本的重試
  才穩定重現、修掉、驗證過的，不是這支測試每次自動保證會抓到。`DB::transaction($fn, 3)`
  這個修法本身是正確且符合 Laravel 慣例的防禦性修正，即使 regression test 對這一項的
  保護力不是 100%。
- 實測：全部 63 個測試（含新增 5 個）、179 個 assertion 全綠；`ReviewServiceConcurrencyTest`
  連續跑 3 次穩定通過。

**額外處理（Phase 9 留下的兩項缺口，趁這次一併驗證掉）：**

- **重現並修掉 Router guard 的競態 bug**：真的拿剛才手動改成 `role=admin` 的帳號，
  瀏覽器直接硬導航到 `/admin`——確認真的會被導回首頁，不是猜測。根因比 Phase 9 記錄的
  更深一層：一開始只把 `app.mount()` 延到 `fetchCurrentUser()` resolve 之後還是沒用，
  因為 Vue Router 4 的初始導航是在 `app.use(router)` 當下就觸發，不是等 `app.mount()`；
  真正有效的修法是連 `app.use(router)` 本身都要延後到 `fetchCurrentUser()` resolve 之後
  （`resources/js/main.ts`）。修完後同一個瀏覽器 session 重新硬導航到 `/admin`，正確停留
  在管理後台，列出待審核回報／評論。
  - 順便走了一次完整 Admin 流程（先前只有程式碼審視過）：核准一筆回報（消失於待審核
    清單）、隱藏一則評論（`GET /restaurants/{id}` 的 rating 立刻掉回 0.0(0)，證明
    `RecalculateRestaurantRatingJob::dispatchSync()` 真的被 Admin 動作觸發）。
- **抓到並修掉真的 Mobile responsive bug**：切到 375px 寬瀏覽器，`document.documentElement`
  的 `scrollWidth`（437px）大於 `clientWidth`（375px）——不是憑感覺猜的，是量出來的真實
  橫向溢出。根因：`.app-header nav` 跟 `HomeView` 的 `.hero-controls` 都是沒有
  `flex-wrap` 的 flex row，導覽列連結跟「搜尋框＋使用目前位置」按鈕在窄螢幕硬擠成一行。
  修法：兩處都加 `flex-wrap: wrap`，`.app-header`／`.hero` 的 padding 縮小、
  `white-space: nowrap` 避免文字被硬折斷。修完後 `scrollWidth === clientWidth === 375`，
  首頁／餐廳列表／餐廳詳情／管理後台四個頁面都重新在 375px 寬下驗證過無橫向溢出。

**未完成 / 等待確認：**

- 前端仍然沒有 Vitest 元件測試或 Playwright E2E——這次新增的 `lib/geo.ts`（從
  `HomeView.vue` 抽出來的 Haversine 距離計算，原本是行內函式，抽出來才有辦法脫離
  Leaflet 地圖元件單獨測）是唯一的前端自動化測試，golden path 仍然靠手動瀏覽器驗證。
- `veggiemap_testing` 的建庫腳本化只驗證過「對已存在的資料庫重跑」，沒有實際刪掉整個
  Docker volume 從零驗證過 `docker-entrypoint-initdb.d` 那條全新 volume 路徑（會動到
  這台機器上其他人的開發資料，沒有必要冒這個風險去驗證一段邏輯很單純的 SQL）。

## 2026-08-24 — Phase 12：GitHub Actions CI

**完成：**

- `.github/workflows/ci.yml`：兩個平行 job。
  - **Backend**：`shivammathur/setup-php@v2`（PHP 8.2 + pdo_mysql/mbstring/bcmath/gd/zip）
    → 建 `.env` → `composer install` → `php artisan key:generate` → 建前端資產（見下方
    「過程中抓到的 bug」）→ `pint --test` → `phpstan analyse`（Larastan）→ 等 MySQL service
    container 就緒 → `migrate --force` → `php artisan test`。
  - **Frontend**：`actions/setup-node@v4`（Node 22）→ `npm ci` → `eslint` → `vue-tsc` →
    `vitest run` → `npm run build`。
- 新增 `larastan/larastan` + `phpstan.neon`（level 5）。第一次跑出 12 個錯誤，不是雜訊——
  `Restaurant`／`Review`／`RestaurantReport`／`RestaurantConfidenceScore` 的關聯方法回傳型別
  都只寫裸的 `BelongsTo`／`HasMany`／`HasOne`，沒有帶泛型，導致 PHPStan 完全看不穿
  `$this->restaurant->id` 這種透過關聯存取的寫法。補上 `@return BelongsTo<Restaurant, $this>`
  這類泛型 docblock 是全面性修正（不是只在出錯的地方局部繞過），順便也拿掉了
  `OsmRestaurantProvider`／`NominatimGeocodingProvider` 裡兩個多餘的 `?->`（`RequestException`
  的建構子簽章證明 `$response` 永遠不是 null，PHPStan 講對了）。修完 0 error。
- 新增 ESLint（flat config，`eslint-plugin-vue` + `@vue/eslint-config-typescript`）。
  抓到的都是真問題：一個沒用到的 import、四處 `catch (e: any)` 直接存取
  `e?.response?.data?.error?.message` 這種完全沒型別保護的寫法。改成
  `resources/js/lib/apiError.ts` 的 `extractApiErrorMessage`／`extractApiErrorFields`，
  用 axios 的 `isAxiosError` 做真正的型別窄化，不是隨便塞個 `unknown` 應付 lint。瀏覽器
  重新走過一次登入錯密碼的流程，確認改完之後錯誤訊息還是正確顯示，不是只有 type-check 過。
- 啟用 `pint --test` 前，先跑一次完整（非 `--test`）的 `pint` 格式化全專案——這個 CI gate
  一啟用就會抓到 16 個 Phase 0~8 留下來、從來沒被嚴格模式檢查過的既有風格問題。既然是我
  自己要加這道 CI gate，讓它第一次真的跑就失敗不合理，先修乾淨是前提不是額外加工。

**過程中抓到並修掉的 3 個 bug（第一次真的推上 GitHub Actions 跑才發現，本機 docker-compose
一直是綠燈，因為本機環境有這次 session 留下的殘餘狀態把問題蓋住了）：**

1. **`storage/app/mock/restaurants.json` 從來沒有真的進版控**——`storage/app/.gitignore`
   整個目錄用 `*` 擋掉，Phase 8 建立這個 fixture 時沒有 `git add -f`，這個檔案其實只存在
   於我的本機。任何人 fresh clone 這個 repo，`restaurants:sync --provider=mock` 都會匯入
   0 筆（`RestaurantSyncServiceTest` 期待 5 筆），因為 mock provider 讀不到不存在的檔案。
   這對一個履歷用的 Portfolio 專案是嚴重問題——demo 的保底路徑本身其實是壞的。修法：
   `.gitignore` 加 `!mock/`／`!mock/restaurants.json` 例外，`git add -f` 真的把檔案加進去。
2. **`GET /`（`tests/Feature/ExampleTest.php`）在全新 checkout 上會炸
   `ViteManifestNotFoundException`**——Phase 9 把 `/` 改成 Vue SPA shell（`app.blade.php`
   透過 `@vite` 載入資產），render 這個 view 需要 `public/build/manifest.json`。本機測試
   一直過是因為這個 session 稍早跑過 `npm run build`，`public/build/` 一直躺在本機沒清掉，
   蓋住了「後端測試其實依賴前端建置產物」這個真實耦合。修法：Backend CI job 在跑 Pint/
   PHPStan/PHPUnit 之前先 `npm ci && npm run build`（不是刪掉這個測試打發——`/` 本來就是
   這個專案实际的入口，測它回 200 是有意義的迴歸測試，跟 README「Local Development」講的
   「前端要 build 過整個 app 才能動」是同一件事）。用 `mv public/build /tmp && php artisan
   test tests/Feature/ExampleTest.php` 反向驗證過真的會紅、加回來會綠，不是憑錯誤訊息猜的。
3. **Vitest 在 CI 直接 Startup Error**：`You should not run the Vite HMR server in CI
   environments`——Vitest 預設吃 `vite.config.js`，裡面的 `laravel-vite-plugin` 偵測到
   CI 環境變數直接擋掉。單元測試根本不需要 Laravel 的資產管線，加一份獨立的
   `vitest.config.ts`（只保留 `vue()` plugin，不含 `laravel()`）解決，兩份設定互不干擾。
   本機用 `CI=true npm run test` 重現過失敗、加了 `vitest.config.ts` 之後重現過修好，
   不是照錯誤訊息裡的 `LARAVEL_BYPASS_ENV_CHECK=1` 提示照抄了事（那個做法只是關掉檢查，
   沒有解決「Vitest 不該吃到 Laravel 資產管線設定」這個根本問題）。

**實測：**

`git push` 後用 `gh run watch` 真的看完整整兩次 workflow 執行（不是寫完 yaml 就交差）：
第一次跑就在 100% 真實的全新 checkout 環境下抓到上面 3 個 bug；修完後第二次兩個 job
（Backend 1m13s／Frontend 19s）全綠，`gh run view` 確認 exit code 0。

**未完成 / 等待確認：**

- `gh` CLI 的 OAuth token 一開始沒有 `workflow` scope，push `.github/workflows/ci.yml`
  被 GitHub 拒絕；裝置授權碼（device code）前兩次都在使用者來得及操作前過期，第三次才
  成功——這不是我能自動解決的，純粹是使用者互動時間的問題，記錄下來避免下次以為是
  gh 指令本身有問題。
- CI 沒有 cache Composer/npm 依賴到 GitHub Actions cache（`actions/setup-node` 的
  `cache: npm` 有開，但 `shivammathur/setup-php` 沒接 Composer cache）——目前 Backend job
  1m13s 可接受，等 PR 數量變多、想省 CI 時間時再考慮加。
- `pull_request` trigger 存在，但這個專案目前沒有走 PR 流程（約定是直接 push `main`），
  沒有實際拿一個 PR 驗證過 `pull_request` 事件會不會正確觸發。

## 2026-08-24 — Phase 11：文件收尾（`docs/openapi.yaml`、`docs/observability.md`）

**完成：**

- `docs/openapi.yaml`：OpenAPI 3.0.3，涵蓋全部 20 支已實作端點（`docs/api.md` 表格 + 這次
  順便補上的 Phase 7 admin 端點）。內容直接對照 `routes/api.php`、各 `FormRequest` 的
  `rules()`、各 `Resource` 的 `toArray()` 產出，不是憑印象寫的——寫的過程中重新讀了一輪
  `CreateRestaurantReportRequest`／`DietTypeResource`／`FeatureResource`／
  `RestaurantReportResource`（一般使用者版，跟 Admin 版欄位不同）確認欄位。
- 真的用 `npx @redocly/cli lint` 跑過（不是只求 YAML 語法過），修了 3 個問題：
  1. `nullable: true` 沒有搭配真實 `type` 會被判定違反 OpenAPI 3.0 規範（跟 JSON Schema
     2020-12 的 `type: "null"` 混用是常見誤用，3.0.x 不支援後者）。
  2. `security` 屬性放進 `Authorization` header 的說明文字裡有未跳脫的冒號，YAML 直接
     解析失敗（`mapping values are not allowed here`）——純語法坑，跟 OpenAPI 語意無關。
  3. `info.license.name: MIT` 沒有配 `url` 會被 lint 標記；查證後這個 repo 根本沒有
     `LICENSE` 檔，等於是編了一個不存在的授權條款——不是補 url 敷衍過去，是直接把
     `license` 欄位整個拿掉，等使用者真的決定授權條款再補。
  跑到最後「Your API description is valid. 🎉」，剩下 13 個警告都是 `operation-4xx-response`
  這類建議性規則（例如 `/diets`／`/features` 這種完全沒有參數、不可能驗證失敗的端點，
  硬掰一個 4xx response 反而是說謊，沒有修）。
- `docs/observability.md`：刻意用「有 vs 沒有」的對照表收尾，不是寫一份看起來很完整
  的監控藍圖。查證過才寫的部分：`failed_jobs` 表確實存在（Laravel 11 骨架自帶），但因為
  Phase 6 的 `dispatchSync()` 決定，這張表目前實務上不會有資料——沒有含糊帶過，直接點名
  這是已知限制。API response time／cache hit-miss／DB 慢查詢追蹤三項都誠實標記未實作，
  沒有為了讓文件「看起來完整」就寫成已經做了。

**過程中的副產品：**

寫 OpenAPI 時對照 `routes/api.php` 才發現 `docs/api.md` 的端點清單表格從 Phase 5 之後
就沒更新過，漏了整個 Phase 7 的 Admin 端點（`/admin/reports`、`/admin/reviews` 等 5 條）。
順手補上，這是文件之間互相校對抓到的落差，不是這個 Phase 原本規劃要做的事。

**未完成 / 等待確認：**

- `docs/observability.md` 提到「/up 比 / 更適合當 health check smoke test」，但
  `tests/Feature/ExampleTest.php` 目前還是測 `/`（Phase 12 選擇用「backend job 先 build
  前端資產」解決，而不是換掉測試目標）——這個取捨已經在 Phase 12 記錄過，這裡只是文件
  互相對照時再次確認沒有遺漏，不是新發現的問題。
- OpenAPI 規格沒有跑正式的 contract test（Dredd／Schemathesis 那種自動化打真 API 驗證）。
  有手動用 `curl` 打過幾條代表性的端點交叉核對過（`GET /restaurants/{id}` 詳情頁、
  帶座標的半徑搜尋、`GET /diets`、`sort=distance` 缺座標的 422 錯誤格式），確認欄位跟
  規格寫的一致，包含 `distance_meters`／`confidence_score` 這種條件式欄位（`whenLoaded`／
  `when()` 不成立時是整個 key 消失，不是 key 存在但值是 null——這點規格的敘述文字已經
  講清楚，只是沒有用型別系統強制）。但沒有涵蓋全部 20 支端點，Admin 那幾支（需要 admin
  token）跟寫入類端點沒有逐一核對，這裡誠實記錄範圍。

## 2026-08-24 — Phase 13：部署文件

**完成：**

- `docs/deployment.md`：方案 A（EC2+RDS+ElastiCache）／方案 B（ECS Fargate，未來 scale
  用，只記錄架構方向不展開步驟）比較與選擇理由；方案 A 的完整步驟（RDS／ElastiCache
  佈建、EC2、production `.env` 覆寫清單、建置指令、HTTPS、admin 帳號建立、Queue
  Worker／排程建議、安全性、回滾策略）。
- 開頭先列「目前還不是 production-ready」的缺口對照表（沒有 Horizon、沒有
  `users:promote`、沒有排程、`CVE-2026-48019` 未處理、Nominatim 商業政策偏保留），
  每項標記「部署前要不要處理」，不是寫成一份看起來萬事俱備的文件。
- 重新真的跑了一次 `composer audit`（不是憑 Phase 1 記錄的舊印象），確認
  `CVE-2026-48019`（Laravel CRLF injection in default email rule）現況依然存在，
  修法還是升級到 12.60+/13.10+，寫進部署文件的「安全性：部署前必須處理」。

**未完成 / 等待確認：**

- 沒有實際執行任何 AWS 資源佈建或部署——沒有 credentials，也沒有使用者確認要花錢起
  infra，依照總 prompt 規則停在文件階段。
- 方案 B（ECS Fargate）只記錄架構方向，沒有寫逐步操作——這個專案目前的規模用不到，
  真的要用的時候再回來展開。

## 2026-08-24 — 補：`RuleBasedRecommendationService`（總體規劃第 29／30 節，Phase 0 就設計過但沒做）

**發現過程：** Phase 0 的 `docs/architecture.md`「AI 預留」段落早就寫好
`RecommendationServiceInterface`／`RuleBasedRecommendationService`／`config/recommendation.php`
的設計，而且明講「第一版只有 RuleBasedRecommendationService 實作」——這不是 AI/ML，是
MVP 範圍內的東西，「明確不做的事」那段排除的是 `Recommendation ML`（AI 版本），不是
Rule-based 版本本身。但 Phase 9 做前端首頁「推薦餐廳」時，直接在 `HomeView.vue` 用
`[...restaurants].sort((a,b) => b.rating - a.rating).slice(0,6)` 打發，從來沒有實作
過設計文件裡講的這個服務——是重新過一遍總體規劃 md 對照現有程式碼才抓到的落差。

**完成：**

- `config/recommendation.php`：六個分量權重（distance/rating/vegetarian_confidence/
  feature_match/popularity/freshness，對應總體規劃第三十節公式）、
  `max_features_expected`、`freshness_window_days`、`candidate_pool_size`。
- `RecommendationServiceInterface` + `RuleBasedRecommendationService`
  （`app/Services/Recommendation/`）：候選集合 `rank()`，每個分量正規化到 0~1 再加權，
  在候選集合內部排序（不是跨請求可比較的絕對分數，跟半徑搜尋的 distance 一樣是
  bounded-context 的相對值）。`AppServiceProvider` 綁定介面，未來換
  `AIRecommendationService` 只改綁定這一行。
- `RestaurantRepository::candidatesForRecommendation()`：復用既有 `search()` 的半徑搜尋，
  不是另開一套查詢邏輯，只是多 eager load 算分需要的 `dietTypes`／`features`／
  `confidenceScore`。
- `GET /api/v1/restaurants/recommended`（`RecommendedRestaurantRequest` 驗證
  latitude/longitude 必填），route 註冊在 `/restaurants/{restaurant}` **之前**——
  沒有排對順序的話 Laravel 會把 "recommended" 當成 route model binding 的 id 去查，
  這是新增靜態路徑段路由時的標準陷阱，寫的時候就注意到了。
- `RestaurantResource` 新增 `recommendation_score`（`when(isset(...))`，只有這支端點
  回應才會有這個欄位，跟既有 `distance_meters` 同一套模式）。
- `HomeView.vue` 的「推薦餐廳」改成真的打這支新端點（`Promise.all` 跟原本的 bounds
  搜尋並行），不再是前端隨便排序既有列表那幾筆。

**過程中抓到並修掉的問題（不是一次寫對）：**

1. `EloquentCollection::make($paginator->items())->load(...)` 一開始寫成
   `collect(...)->load(...)`——`Illuminate\Support\Collection`（`collect()` 回傳的型別）
   沒有 `load()` 方法，那是 `Illuminate\Database\Eloquent\Collection` 才有的，本機測試
   直接噴 `BadMethodCallException`，改用 `EloquentCollection::make()` 解決。
2. PHPStan（Larastan）誤判 `$restaurant->confidenceScore?->score` 的 `?->` 是多餘的
   （宣稱這個關聯永遠非 null），但這是 Larastan 對 eager-loaded `HasOne` 的已知限制，
   不是真的——`test_score_is_bounded_between_zero_and_one` 那個測試建立的餐廳完全沒有
   `RestaurantConfidenceScore` 紀錄，`?->` 拿掉的話那個測試會直接炸
   `Attempt to read property on null`，實測驗證過才敢用 `@phpstan-ignore` 蓋掉這條規則，
   不是看到報錯就無腦加 ignore。
3. `Restaurant` model 動態設定 `recommendation_score`（跟既有的 `distance` 一樣不是
   資料表欄位）在 PHPStan 底下被判定成「寫入未宣告屬性」——`distance` 沒被抓到是因為
   只有「讀」被放寬檢查，「寫」是不同規則；補 `@property float|null $recommendation_score`
   到 Restaurant model 的 class docblock 解決，跟既有 `$distance` 的註記方式一致。

**實測：**

- 3 個新的 `RuleBasedRecommendationServiceTest`（含「故意讓分數排序邏輯反過來，測試
  真的會紅」的自我驗證）+ 2 個新的 HTTP 層測試（`RestaurantTest`），加上既有測試共
  68 個、211 個 assertion，全綠。Pint／PHPStan 都乾淨。
- 真打 `curl http://localhost:8080/api/v1/restaurants/recommended?...`，回應正確依
  `recommendation_score` 遞減排序。
- 瀏覽器打開首頁，「推薦餐廳」區塊網路請求確認真的打到新端點（不是舊的前端排序），
  console 無錯誤。
- `docs/openapi.yaml` 補上這支端點與 `Restaurant.recommendation_score` 欄位，
  `npx @redocly/cli lint` 重新跑過仍然 0 error。`docs/api.md`／`docs/architecture.md`
  同步更新，`architecture.md` 的「AI 預留」標題從「不在 MVP 範圍」改成標明
  Rule-based 版本已實作，只有 AI 版本還沒做。

## 2026-08-24 — 補：Redis Search/Detail Cache + Rate Limiting（總體規劃第 16／17／42 節）

**發現過程：** 同一輪重新對照總體規劃 md 抓到的第二個落差，比 Recommendation 那個更嚴重——
「Redis Cache」跟「Rate Limiting」是總體規劃開頭第一段就列出的核心能力清單項目
（跟 RESTful API／MySQL／Queue 並列），第十六／十七節也明講要對 `/restaurants` 做
search cache（`restaurants:search:{hash}`，300s）／detail cache（`restaurant:{id}`，
600s）／清快取／Redis-based rate limiter。查證發現：**這兩項在這個專案裡完全是 0%
實作**——`grep` 全專案找不到任何 `throttle` middleware、找不到 `RateLimiter::for`，
Redis cache 只有 Phase 8.5 的 `GET /geocode` 一處在用，核心的 `/restaurants` 列表／詳情
每一次請求都是真的打 MySQL。這不是「還沒排到」的技術債，是履歷/面試情境下如果有人
真的去讀程式碼或戳 API，會直接戳破「這個專案有用 Redis」這個宣稱的落差，所以列為
高優先立刻補。

**完成：**

- `RestaurantRepository::search()`：包一層 `Cache::tags(['restaurants'])->remember()`，
  key 用排序過的完整 filters（含 cursor/sort/per_page）算 md5，不同頁/不同條件各自
  獨立的 cache entry，TTL 300s。
- `RestaurantRepository::findForDetail()`：新方法，`restaurant:{id}` cache，TTL 600s。
  **關鍵設計決定**：`RestaurantController::show()` 原本用 Laravel implicit route model
  binding（`Restaurant $restaurant`），這樣 Laravel 會在進 controller「之前」就先查一次
  DB 做綁定，包再多 `Cache::remember()` 都是狀後諸葛——改成收原始 `int $restaurant`，
  查詢本身（含 eager load）整個包進 cache closure，才是真的省掉 DB 查詢。
- `RestaurantObserver`／`RestaurantConfidenceScoreObserver`（`app/Observers/`）+
  共用的 `RestaurantCacheInvalidator`：`Restaurant`／`RestaurantConfidenceScore` 存檔或
  刪除時，`Cache::forget("restaurant:{id}")` + `Cache::tags(['restaurants'])->flush()`。
  兩個 model 都要掛，因為 confidence score 是獨立的表，只更新它不會觸發 Restaurant
  的 saved event，但 detail cache 裡內嵌了 confidence_score 欄位。**不做**
  `Cache::flush()`（總體規劃第十七節明講禁止），tags 機制只清跟餐廳相關的 key。
- `AppServiceProvider::boot()` 新增 `RateLimiter::for('api', ...)`：60 次／分鐘，依
  登入使用者 id 或 IP 分桶，底層走 `CACHE_STORE=redis`（已經是 Redis，不需要額外套件）。
  `routes/api.php` 整個檔案包一層 `Route::middleware('throttle:api')`——不是只套
  文件明講的 `/restaurants`，因為其他匿名可存取端點（`/diets`／`/geocode` 等）一樣有
  被打爆的風險。
- 6 個新測試（`tests/Feature/Api/RestaurantCachingTest.php`）：重複搜尋/詳情請求
  **直接斷言 `DB::getQueryLog()` 是空陣列**（不是只驗證回應內容對，回應對不代表真的
  沒打 DB）、不同篩選條件各自獨立快取、修改餐廳／修改 confidence score 都會讓 detail
  cache 正確失效、超過限流回 429。

**過程中做的自我驗證（不是寫完就交差）：**

1. 拔掉 `findForDetail()` 的 `Cache::remember()` 重跑測試——「repeated detail request
   hits cache not the database」真的紅了（`assertCount(0)` vs 實際 5 個 query）。
2. 拔掉 `AppServiceProvider::boot()` 裡兩個 `::observe()` 註冊重跑測試——兩個
   invalidation 測試都真的紅了（改了餐廳名字/confidence score，detail API 還是回舊值）。
   加回去後全綠。
3. **不只信任 phpunit 用的 array cache store**：用 `redis-cli -n 1 KEYS "*"`
   直接看真的 Redis（`REDIS_CACHE_DB=1`），確認 `restaurant:1` 這個 key 真的存在，
   而且 tags 機制產生的 hash key／`:timer` companion key 也在；用 tinker 改一筆餐廳
   名稱後重打 API，確認真的從 DB 撈到新名稱，不是繼續吐舊的 Redis 快取內容；
   `curl -sI` 看到 `X-RateLimit-Limit: 60`／`X-RateLimit-Remaining` header 真的存在。
   這三步都是對著這台機器上真正在跑的 Redis／MySQL container 做的，不是只看
   phpunit 綠燈就結案。

**實測：**

74 個測試（含新增 6 個）、229 個 assertion 全綠，Pint／PHPStan 乾淨。`docs/api.md` 新增
Caching／Rate Limiting 兩個段落，`README.md` 的 Caching Strategy／Security 段落改成
反映真實現況（原本這兩段其實是 Phase 11 寫 README 時就內容超前於實作的「應該有」，
不是真的有——現在補齊，文件才跟程式碼一致）。

**未完成 / 等待確認：**

- Cache 命中率沒有另外記錄追蹤（見 [docs/observability.md](observability.md)），
  只驗證過「有沒有打 DB」，沒有量測 production 流量下的實際命中率。
- Rate limit 目前是全域 60/分鐘一組規則，沒有依端點類型（例如寫入類 vs 唯讀類）
  細分不同限流值——這個專案的規模，細分限流的 ROI 目前偏低，先用同一組簡單規則。

## 2026-08-24 — 補：安裝 Laravel Telescope（本機除錯工具）

上一個 session 中斷前已裝到一半（`composer.json` 加了 `laravel/telescope`、
`TelescopeServiceProvider`／`config/telescope.php`／migration 都已產生並跑過 migrate），
但沒有 commit、也沒有寫進 `docs/todo.md`／`docs/progress.md`。接手後先驗證現況再決定
要繼續完成還是回退：

- `AppServiceProvider::register()` 只在 `local`／`testing` 環境才 `$this->app->register(TelescopeServiceProvider::class)`，
  不進 `bootstrap/providers.php` 固定清單——production 不會多一層中介層開銷，`/telescope`
  路由在 production 也不存在，比只靠 `TelescopeServiceProvider::gate()` 白名單多一層防護。
  `laravel/telescope` 掛在 `require`（不是 `require-dev`），這是 Laravel 官方文件明講的
  「只在特定環境註冊」模式所需的寫法——包管理層面它仍要能在任何環境被 autoload 到，
  只是 provider 註冊本身被環境擋掉。
- gate() 白名單目前是空陣列（`in_array($user->email, [])`），代表 production 環境下沒有
  任何人能看 `/telescope`（即使繞過上面那層 provider 限制），這是刻意的保守預設，不是
  漏寫。

**自我驗證：**

1. `php artisan migrate:status` 確認 `create_telescope_entries_table` 已經在 batch 2 跑過。
2. `php artisan test`：74 個測試、229 個 assertion 全綠，跟裝之前的數字一致——確認
   Telescope 沒有干擾既有功能。
3. Pint `--test`／PHPStan level 5 都乾淨。
4. 瀏覽器真的打開 `http://localhost:8080/telescope`，Requests 分頁真的顯示出剛才
   `php artisan test` 過程中打過的 `/api/v1/diets`／`/api/v1/restaurants`／
   `/api/v1/restaurants/1` 三筆真實請求記錄，不是空頁面或 500。

`README.md` Observability 段落補一小段說明用途與環境限制範圍。
