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

## 下一步（等待確認後）

Phase 2：Database（migrations／models／relationships／factories／seeders／indexes），
依 `docs/database.md` 的表格設計實作。
