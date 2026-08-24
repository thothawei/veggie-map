# VeggieMap

> A scalable vegetarian-friendly restaurant discovery platform built with Laravel, MySQL, Redis and modern geospatial search.

素食 × 地圖 × 多條件搜尋 × 寵物友善 × 使用者回報 × 素食可信度的餐廳探索平台。這是一個以「展示中高階
Backend Engineer 系統設計能力」為目標的作品專案，不是一個簡單 CRUD demo。

現況：後端 API（Phase 0–8.5）已完成並實測；前端（Vue 3 + Leaflet）尚未開始。詳細進度見
[docs/progress.md](docs/progress.md)，剩餘規劃見 [docs/todo.md](docs/todo.md)。

## Features

- **地理空間搜尋**：MySQL `POINT SRID 4326` + Spatial Index，兩段式查詢（Bounding Box 過濾 →
  `ST_Distance_Sphere` 精算距離），不把整張表撈出來在 PHP 算距離。
- **多條件篩選**：keyword／city／district／diet（vegan/vegetarian/…）／price_level／rating_min／
  pet_friendly／parking，搭配 cursor pagination。
- **地址搜尋（Geocoding）**：使用者輸入地名/地標（例如「台中一中街」）轉經緯度，串接
  `GET /restaurants` 半徑搜尋，Redis cache 擋掉重複查詢。
- **素食可信度系統**：`restaurant_verifications`（店家自主認領／菜單驗證／使用者確認／照片／
  外部資料源／管理員確認六種類型，各自帶可調整權重）加總成 0–100 的 confidence score。
- **使用者系統**：Sanctum Bearer Token 認證、收藏、評論（同一使用者對同一餐廳只留一筆
  active review，重新評論＝覆蓋並保留歷史）、店家問題回報。
- **Admin 審核**：回報／評論的 approve／reject／hide 端點，Policy 限定 admin 角色。
- **外部資料匯入**：`restaurants:sync` 從 OpenStreetMap Overpass API 匯入餐廳資料，Adapter Pattern
  可替換成其他資料源；同名＋距離 <100m 標記為 possible duplicate，不自動合併/刪除。

## Architecture

```
┌────────────┐      ┌──────────────────────────────────────────┐
│  Vue 3 SPA │─────▶│ Laravel API (/api/v1)                     │
│  + Leaflet │◀─────│  Controller → FormRequest → Service       │
│ (Phase 9,  │      │  → Repository → Eloquent → MySQL          │
│  尚未實作)  │      │         │                                │
└────────────┘      │         ├─▶ Redis (search/detail cache)   │
                     │         └─▶ Queue jobs (見下方 Queue 說明)│
                     └────────────────┬───────────────────────────┘
                                       │
                     ┌─────────────────▼─────────────────┐
                     │ app/Services/External/             │
                     │  RestaurantProviderInterface        │
                     │   ├─ OsmRestaurantProvider (Overpass)│
                     │   └─ MockRestaurantProvider          │
                     │  GeocodingProviderInterface          │
                     │   └─ NominatimGeocodingProvider       │
                     └───────────────────────────────────┘
```

完整技術選型與「Why」寫在 [docs/architecture.md](docs/architecture.md)。

## Tech Stack

**Backend**：Laravel 11（PHP 8.2）、MySQL 8（Spatial functions）、Redis（cache + queue driver）、
Laravel Sanctum、Laravel Pint（formatter）。

**Frontend（規劃中，見 [docs/todo.md](docs/todo.md) Phase 9）**：Vue 3、TypeScript、Vite、Pinia、
Vue Router、Axios、Leaflet。

**Infra**：Docker Compose（app / nginx / mysql / redis）。

## Database Design

13 張表：`restaurants`、`diet_types`／`restaurant_diet_types`、`features`／`restaurant_features`、
`menu_items`、`restaurant_verifications`、`restaurant_confidence_scores`、`restaurant_reports`、
`users`、`favorites`、`reviews`、`external_api_logs`、`personal_access_tokens`（Sanctum）。

欄位設計、Index 選擇的理由（哪些 index 為什麼建、複合 index 怎麼排序）完整記錄在
[docs/database.md](docs/database.md)。

## API Documentation

所有端點前綴 `/api/v1`，統一回應格式：

```json
// 成功
{ "success": true, "data": {}, "meta": {} }

// 錯誤
{ "success": false, "error": { "code": "RESTAURANT_NOT_FOUND", "message": "Restaurant not found" } }
```

| Method | Path | 說明 | 認證 |
|---|---|---|---|
| GET | `/restaurants` | 列表＋多條件搜尋＋半徑搜尋 | 選用 |
| GET | `/restaurants/{id}` | 詳情（含 diet types／features／menu／confidence score） | 選用 |
| GET | `/restaurants/{id}/reviews` | 評論列表 | 選用 |
| POST | `/restaurants/{id}/favorite` | 加入收藏 | 必須 |
| DELETE | `/restaurants/{id}/favorite` | 取消收藏 | 必須 |
| POST | `/restaurants/{id}/reviews` | 新增評論（覆蓋舊的 active review） | 必須 |
| POST | `/restaurants/{id}/reports` | 回報店家問題 | 必須 |
| GET | `/diets` \| `/features` | 固定清單 | 無 |
| GET | `/geocode?q=` | 地址/地標轉經緯度 | 無 |
| POST | `/auth/register` \| `/auth/login` | 註冊／登入 | 無 |
| POST | `/auth/logout` | 登出（撤銷 token） | 必須 |
| GET | `/me` \| `/me/favorites` | 個人資料／收藏列表 | 必須 |
| GET/POST | `/admin/reports`、`/admin/reviews` | Admin 審核回報／評論 | 必須（admin） |

範例：

```
GET /api/v1/restaurants?latitude=24.1477&longitude=120.6736&radius=5&diet=vegan&pet_friendly=1
GET /api/v1/geocode?q=台中一中街
```

完整查詢參數、分頁規則、錯誤碼列在 [docs/api.md](docs/api.md)。`docs/openapi.yaml` 排在
Phase 11，尚未產出。

## Local Development

```bash
git clone https://github.com/thothawei/veggie-map.git
cd veggie-map
cp .env.example .env
docker compose up -d --build
docker compose exec app composer install
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate --seed
```

API：`http://localhost:8080/api/v1`（host port 依 `docker-compose.yml` 設定，見下方 Docker 章節）。

## Docker

`docker-compose.yml` 定義四個服務：`app`（PHP 8.2-fpm）、`nginx`、`mysql`、`redis`。本機若
3306/80 已被其他專案佔用，host 對外映射改成 `3307`/`8080`（容器內部 port 不變）。

```bash
docker compose up -d      # 啟動
docker compose ps         # 確認四個服務都是 Running
docker compose logs -f app
```

## Testing

```bash
docker compose exec app php artisan test
```

58 個 Feature/Unit test、141 個 assertion，涵蓋所有已實作端點（含 Sanctum 401/token 撤銷、
Policy 授權、review 覆蓋邏輯、confidence score 計算、`restaurants:sync` 冪等性與去重）。

測試用 MySQL（非 sqlite in-memory）——schema 用了 `POINT`／`ST_Distance_Sphere`／`MBRContains`
等 MySQL 專屬空間函式，sqlite 跑不起來。跑測試前需先手動建立 `veggiemap_testing` 資料庫：

```sql
CREATE DATABASE veggiemap_testing;
GRANT ALL PRIVILEGES ON veggiemap_testing.* TO 'veggiemap'@'%';
```

（這個手動步驟尚未腳本化，CI 落地前必須自動化，見 [docs/todo.md](docs/todo.md) Phase 10。）

## External APIs

| API | 用途 | 備註 |
|---|---|---|
| OpenStreetMap Overpass API | `restaurants:sync` 批次匯入餐廳資料 | 免費、無 API key，僅離線批次使用，30s timeout + 429 退避重試 |
| Nominatim | 使用者主動地址搜尋（`/geocode`） | 免費、每秒最多 1 次請求，5s timeout + 429 退避重試，同查詢字串 Redis cache 1 天 |
| Mock Provider | 開發/測試環境保底 | Overpass 斷線或本機無網路時仍可 demo，5 筆內建 fixture |

完整的評估過程（是否免費、rate limit、license、是否允許 Portfolio 使用）見
[docs/external-apis.md](docs/external-apis.md)。**踩過的坑**：Nominatim 對帶有
`example.com` 這類常見教學範例網域的 `User-Agent` 字串直接回 403，不是程式碼邏輯問題。

## Caching Strategy

- 搜尋結果／餐廳詳情：Redis cache，依查詢條件組 key，短 TTL（避免重複的 Spatial Query 打 DB）。
- 地址搜尋：`geocode:{md5(query)}`，TTL 1 天（配合 Nominatim rate limit，而非效能考量）。
- **不使用** `Cache::flush()`——資料異動只清相關 key，避免整個 Redis 被清空影響其他快取。

## Queue Architecture

`CalculateRestaurantScoreJob`、`RecalculateRestaurantRatingJob` 都實作 `ShouldQueue`，但目前
**沒有安裝 Laravel Horizon、也沒有跑 queue worker**，呼叫端一律用 `dispatchSync()` 同步執行，
避免「程式碼看起來對、實際上因為沒有 worker 消化而永遠不會更新」的死路徑。這是誠實記錄的已知
技術債，見 [docs/todo.md](docs/todo.md)——等 Horizon 裝上、worker 跑起來後，把 `dispatchSync`
改回 `dispatch()` 即可，Job 類別本身不用改。

## Geospatial Search

`restaurants.location` 是 `POINT SRID 4326` + Spatial Index。查詢分兩段：先用經緯度算出
Bounding Box，`MBRContains` 過濾出候選集合（能用到 Spatial Index），再對候選集合算
`ST_Distance_Sphere` 精確距離排序。避免對整張表做未過濾的距離計算。細節與 SQL 範例見
[docs/database.md](docs/database.md)。

## Security

- 密碼 hashing：Laravel 原生（不自行實作）。
- 認證：Laravel Sanctum Bearer Token（API-only，未使用 SPA cookie 模式）。
- 授權：Policy（`ReviewPolicy`／`RestaurantReportPolicy`），Admin 動作額外檢查 `role`。
- Mass assignment：所有 Model 明確宣告 `$fillable`。
- API 錯誤格式統一經 `ApiExceptionRenderer`，不外洩 Laravel 預設例外堆疊訊息（production）。
- `.env`／API Key 不進版控（`.gitignore` 已排除）。
- 已知待處理：`composer audit` 的 `CVE-2026-48019`（email 驗證規則 CRLF injection），MVP 範圍
  接受，正式升版前需處理，見 [docs/progress.md](docs/progress.md)。

## Performance

- 列表 API 用 `select()`／`with()` 避免 N+1，cursor pagination 取代 offset pagination。
- `per_page` 上限 100，避免使用者要求超大分頁拖垮資料庫。
- Rating／confidence score 是快取欄位（`restaurants.rating`／`rating_count`、
  `restaurant_confidence_scores.score`），不即時計算，由 Job 批次更新。

## CI/CD

尚未實作（[docs/todo.md](docs/todo.md) Phase 12）。規劃：`.github/workflows/ci.yml` 跑
Install → Pint → PHPStan → 自動建測試庫 → PHPUnit → build。

## Future Roadmap

- Vue 3 + Leaflet 前端（Phase 9）
- Laravel Horizon + 真正的 queue worker（目前 `dispatchSync` 頂著）
- `users:promote` Admin 帳號晉升指令
- `routes/console.php` 排程自動跑 `restaurants:sync`／批次計算 Job
- GitHub Actions CI、部署文件（AWS，需使用者確認 credentials 後才執行）
- 更長期：AI 推薦、Menu OCR、使用者聲譽系統（見總體規劃文件，第一版 MVP 刻意不做）

---

完整開發歷程（含踩過的坑與為什麼這樣設計）逐 Phase 記錄在 [docs/progress.md](docs/progress.md)。
