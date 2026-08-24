# API Overview — VeggieMap

Phase 0 產出的端點清單與回應格式約定。完整的 OpenAPI 規格（`docs/openapi.yaml`）在 Phase 11 產出；
這份文件是 Phase 3~5 實作時的對照表。

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
| GET | `/restaurants/{id}` | 詳情 | 選用 |
| POST | `/restaurants/{id}/favorite` | 加入收藏 | 必須 |
| DELETE | `/restaurants/{id}/favorite` | 取消收藏 | 必須 |
| POST | `/restaurants/{id}/reviews` | 新增評論 | 必須 |
| GET | `/restaurants/{id}/reviews` | 列出評論 | 選用 |
| POST | `/restaurants/{id}/reports` | 回報 | 必須 |
| GET | `/diets` | 飲食類型清單 | 無 |
| GET | `/features` | 特色清單 | 無 |
| GET | `/me` | 目前使用者 | 必須 |
| GET | `/me/favorites` | 我的收藏 | 必須 |
| POST | `/auth/register` | 註冊 | 無 |
| POST | `/auth/login` | 登入 | 無 |
| POST | `/auth/logout` | 登出 | 必須 |

## `GET /restaurants` 查詢參數

`keyword`, `latitude`, `longitude`, `radius`（公里）, `city`, `district`, `diet`, `price_level`,
`rating_min`, `pet_friendly`, `parking`, `open_now`, `sort`（distance/rating/popular/newest，
預設 `distance`；帶 `latitude`+`longitude` 才可用 `distance`）, `page`, `per_page`（預設 20，上限 100）。

範例：

```
GET /api/v1/restaurants?latitude=24.1477&longitude=120.6736&radius=5&diet=vegan&pet_friendly=1
```

## Pagination

列表 API 採 Cursor Pagination（依 `id` 遞增）而非 offset，避免大資料集下 `OFFSET N` 效能劣化
（見 `docs/architecture.md`）。回應 `meta` 需帶 `next_cursor`。

## Validation / Authorization

每個會寫入的端點對應一個 FormRequest（`SearchRestaurantRequest`、`CreateReviewRequest`、
`CreateRestaurantReportRequest`…）與一個 Policy（`ReviewPolicy`、`RestaurantPolicy`、`ReportPolicy`、
`FavoritePolicy`）。Controller 只做「呼叫 Service／回傳 Resource」，不做欄位驗證與授權判斷。
