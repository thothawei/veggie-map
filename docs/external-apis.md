# External APIs — 調查結果

調查日期：2026-08-24。來源：`public-apis/public-apis` 的 Food & Drink 分類、OSM 官方文件、
Open Food Facts 官方文件（皆以 WebFetch 直接讀取原始文件確認，非憑記憶）。

## 結論先講

`public-apis` 清單裡的 Food 分類**沒有一個免費、免金鑰、且能拿到「餐廳＋地理位置＋素食標籤」的
API**——多數需要 `apiKey`（Spoonacular、Edamam、Tasty…），且都是「食譜／營養成分」導向，不是
「附近餐廳」導向。這正是總 prompt 第 112 行預期的情況，因此照指示改採：

**OpenStreetMap（Overpass API 匯入）＋ 自建 Restaurant Database ＋ MockRestaurantProvider**

OSM 對這個專案剛好是最佳解，不是退而求其次：OSM 的 `amenity=restaurant`/`cafe` 節點本來就支援
`diet:vegetarian=yes/only`、`diet:vegan=yes/only` 等標籤，等於資料源頭已經有素食分類，比任何一個
Food API 都更貼合 VeggieMap 的核心需求。

## 評估表

| API | 用途 | 免費 | API Key | Rate Limit | License | 建議 |
|---|---|---|---|---|---|---|
| **Overpass API**（overpass-api.de） | 匯入 OSM 上的餐廳／咖啡廳節點（含 diet:* 標籤）作為初始資料源 | 是 | 否 | 官方建議「每日 < 10,000 次查詢、< 1GB」，429 時退避 30 秒 | ODbL（資料本身） | **採用**，作為 `OsmRestaurantProvider` 的資料來源，僅用於離線批次匯入（`restaurants:sync`），不做即時查詢 |
| **Nominatim**（OSM 官方） | 使用者輸入地址／地標轉經緯度（forward geocoding），例如搜尋框輸入「台中一中街」 | 是 | 否 | **每秒最多 1 次請求**，批次任務每分鐘 4 次；必須帶合法 `User-Agent`/`Referer`，不可用 http 函式庫預設值 | ODbL | **採用**，但僅用於「使用者主動搜尋地點」這種低頻互動，且必須加 Redis cache（同一查詢字串 cache TTL 建議 1 天）避免撞 rate limit。政策明確要求「以地理編碼為主功能的商業服務要自架」——VeggieMap 的主功能是餐廳搜尋不是地理編碼服務本身，屬允許範圍，但正式上線需自行評估是否改用付費地理編碼商 |
| **Open Food Facts** | 包裝食品成分／素食分類查詢 | 是，完全免費，「as long as 1 API call = 1 real scan by a user」（禁止整庫爬取，已提供每日全量匯出） | 否 | 官方文件未列出明確數字上限；建議合理使用 | ODbL（結構）＋DbCL（內容）＋CC-BY-SA（圖片） | **不採用於 MVP**：VeggieMap 是「餐廳」平台不是「包裝食品」平台，這個 API 用途對不上核心需求，硬接會是本專案規則第 20 條講的「沒有實際價值的複雜度」。保留在 `docs/architecture.md` 的 Roadmap 供未來「菜單 OCR／包裝食品分類」功能使用 |
| Spoonacular / Edamam / Tasty / TheMealDB 等 | 食譜、營養成分 | 需 `apiKey`，多數有嚴格免費額度 | 是 | 各家不同，普遍每日數百次 | 各家商業條款 | **不採用**：用途是食譜／營養素而非「附近有哪些素食餐廳」，跟產品定位不符 |

## Adapter 介面涵蓋的 Provider

依總 prompt 第十九節設計，三個 Provider 全部實作同一介面，可互換：

- `OsmRestaurantProvider`：呼叫 Overpass API，把 OSM node 轉換成 `RestaurantData`。僅在
  `php artisan restaurants:sync` 這個離線指令中使用，不掛在使用者請求路徑上。
  **收錄規則依國別而異**（見下方「收錄規則」段落）。這個篩選必須下在查詢端：台北市 bbox 下
  `amenity=restaurant|cafe` 共 15,974 個節點，篩完只剩 222 個。
  **必須帶 User-Agent**：overpass-api.de 會用 HTTP 406 擋掉 Guzzle 預設 UA，見下方
  Failure Handling 第 6 點。
- `MockRestaurantProvider`：從專案內建的 fixture（`storage/app/mock/restaurants.json`）讀取，
  保證 Overpass API 斷線、改政策、或本機無網路時 Demo 仍可跑。**這是規則第 14 條「無法穩定使用時
  建立 Mock Provider」的直接對應**——Overpass 是免金鑰的公開服務，穩定性不受合約保障，必須有退路。
- `GeocodingProviderInterface` 的實作是 `NominatimGeocodingProvider`，同樣包一層介面，未來要換成
  Google Geocoding 或付費商都只需新增一個 Provider class。

## 收錄規則（2026-08-25 決定）

OSM 的 `diet:vegetarian` / `diet:vegan` 標籤有兩個我們在意的值：`only` 是「整間店都是素／
純素」，`yes` 是「有素食選項」的一般餐廳。**用哪一個當收錄門檻依國別而異**，因為各地
OSM 社群的標註慣例不同——這不是偏好問題，是同一套規則套下去會讓其中一邊失真：

| 範圍 | `only` | `yes\|only` | only 佔比 | 採用規則 |
| --- | --- | --- | --- | --- |
| 台中市 | 177 | 220 | 80% | `only` |
| 東京 23 区 | 46 | 210 | 22% | `yes` |

台灣的標註習慣會明確標 `only`，用 `only` 收得到絕大多數素食店；日本社群慣用
`diet:vegan=yes` 表達「有純素選項」，很少標 `only`，套 `only` 會讓整個東京 23 区只剩
46 家，薄到不可用。

規則跟著同步範圍走，寫在 `EXTERNAL_API_SYNC_BBOXES` 的每一組後面：
`"minLat,minLng,maxLat,maxLng@only;minLat,minLng,maxLat,maxLng@yes"`，省略 `@規則` 時
預設 `only`。`OsmRestaurantProvider` 的建構子只接受 `only`／`yes`，其他值直接丟
`InvalidArgumentException`，不靜默退回預設。

**`yes` 的實際後果要看清楚**：東京匯入的 195 家裡包含 CoCo壱番屋、AFURI、
ドトールコーヒーショップ 這類連鎖店——它們因為「有純素選項」而入列，不是素食餐廳。
這是選 `yes` 必然帶來的結果，不是 bug。

## Failure Handling 對應（總 prompt 第二十節）

Overpass／Nominatim 都是「免費但不保證 SLA」的公開服務，因此：

1. **timeout**：Overpass 匯入設定 30s timeout（單次查詢可能很重），Nominatim 設定 5s。
2. **retry + exponential backoff**：429 時依官方建議至少退避 30 秒，之後採指數退避（30s → 60s → 120s，最多 3 次）。
3. **logging**：所有呼叫寫入 `ExternalApiLog`（見 `docs/database.md`），不記錄任何 key（本來就沒有 key 可記）。
4. **fallback**：`restaurants:sync` 失敗時不影響既有資料，餐廳查詢 API 永遠只讀自家 MySQL，不會因為 OSM 掛掉而讓 `/api/v1/restaurants` 跟著掛。
5. **circuit breaker 概念**：連續 N 次（建議 5 次）呼叫失敗後，`restaurants:sync` 該次執行直接標記失敗並停止，不無限重試耗損資源，改由排程下次再試。
6. **User-Agent 是必要條件，不是禮貌**：`overpass-api.de` 對 Guzzle 預設 User-Agent 直接回
   **HTTP 406 Not Acceptable**（2026-08-25 實測；同一個查詢用 `curl` 帶 UA 回 200）。
   `EXTERNAL_API_OVERPASS_USER_AGENT` 留空時沿用 `EXTERNAL_API_NOMINATIM_USER_AGENT`。
   這個失敗模式特別惡劣：`Http::retry()` 會把 406 包成 `RequestException` 丟出，
   provider 的 catch 回空陣列，`restaurants:sync` 於是印出「created 0」**並回傳成功**——
   看起來是「這個範圍沒有素食店」而不是「被擋了」。`ExternalApiLog` 現在會記下真實狀態碼
   （`HTTP_406`）而不是無資訊量的 `RequestException`，就是為了讓下次一眼看得出差別。

## 待確認 / 風險

- Overpass 官方主實例的「每日 < 10,000 次查詢」是社群建議值不是硬性合約，正式排程同步建議放在離峰時段、
  且用 bounding box 分批查詢（見 `docs/database.md` 的匯入策略），不要一次撈全台灣。
- Nominatim 政策原文對「商業應用」的表態偏保留，見上表；MVP／Portfolio Demo 用途風險低，但若這個專案
  未來要真的上線給大眾使用，需要重新評估是否改用地圖服務商（例如 Google/Mapbox 的付費 Geocoding）。
