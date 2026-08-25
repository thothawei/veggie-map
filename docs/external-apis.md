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

## 收錄規則

OSM 的 `diet:vegetarian` / `diet:vegan` 標籤有兩個我們在意的值：`only` 是「整間店都是素／
純素」，`yes` 是「有素食選項」的一般餐廳。收錄門檻寫在 `EXTERNAL_API_SYNC_BBOXES` 的
`@規則`，規則名稱是 `config/diet.php` 的 `sync_modes` key，不是程式裡寫死的國家判斷。

| 範圍 | `only`（量測） | `yes\|only`（量測） | only 佔比 | 採用規則 |
| --- | --- | --- | --- | --- |
| 台北市 | 222 | — | — | `yes` |
| 台中市 | 177 | 239 | 74% | `yes` |
| 高雄市 | 107 | 310* | 約 35% | `yes` |
| 台南市 | 45 | 187* | 約 24% | `yes` |
| 東京 23 区 | 46 | 210 | 22% | `yes` |

\* 高雄／台南的 `yes|only` 數字是用較寬的量測 bbox 取得，與左欄的 `only` 不是同一個範圍，
所以佔比只能當量級參考。**這個註記本身就是教訓**：2026-08-25 第一版曾把台中寫成 80%，
是拿放寬 bbox 的分子（177）配窄 bbox 的分母（220）算出來的，同一個 bbox 重量後是 74%。
跨範圍的比值不要混用。

日本社群慣用 `diet:vegan=yes` 表達「有純素選項」，很少標 `only`，套 `only` 會讓整個
東京 23 区只剩 46 家，薄到不可用。台灣四市的 only 佔比並不一律偏高（台中 74%，但高雄約
35%、台南約 24%，跟東京相去不遠）。**2026-08-25 Phase B 產品決定**：台灣也收友善店，
四市改 `@yes`。進地圖的預設篩選仍是 `venue_scope=exclusive`（純素食店），要看混合店
得自己選「素食友善」或「全部」——不會一打開變成「全是火鍋」。

規則跟著同步範圍走，寫在 `EXTERNAL_API_SYNC_BBOXES` 的每一組後面：
`"minLat,minLng,maxLat,maxLng@yes"`，省略 `@規則` 時預設 `only`（對齊
`diet.default_sync_mode`）。規則名稱必須是 `config/diet.php` 的 `sync_modes` key，其他值
直接丟 `InvalidArgumentException`，不靜默退回預設。

**`yes` 的實際後果要看清楚**：會收進 CoCo壱番屋、AFURI、星巴克、鼎泰豐這類「菜單有
無肉選項」的店——它們不是素食餐廳。這是選 `yes` 必然帶來的結果，不是 bug。畫面用
`venue_kind=friendly` 標「素食友善」，跟純素食店分開。

**標籤怎麼對到 `diet_types.code`**（2026-08-25 Phase A）：對應表在 `config/diet.php`，
不是 `OsmRestaurantProvider` 裡的常數。`diet:*=only` → exclusive codes（`vegan`／
`vegetarian`），`diet:*=yes` → friendly codes（`vegan_friendly`／`vegetarian_friendly`）。
收錄規則（Overpass 撈哪些值）跟映射（撈進來之後掛哪個 code）是兩件事——東京用 `@yes`
把友善店收進來，但不能把它們標成素食餐廳。

重跑同步時，OSM 管得到的 diet 關聯改成「這次算出的集合」（錯標的 `vegetarian` 會被換掉）；
沒有 `osm_tag` 的手動類型（例如蛋奶素）會留下。特色仍用 `syncWithoutDetaching`。
友善店的 `external_source` 分數走 `config/diet.php` 的 `confidence`，低於 exclusive。

**OSM 沒有逐道菜單。** `restaurants:sync` 不編造 `menu_items`。詳情頁沒菜單時顯示
`menu_empty_message`（友善店：「OSM 標示此店有素食選項，菜單尚未建檔。」），菜單來源是
Admin 寫入或種子資料，不是 Open Food Facts。

## OSM 標籤 → features 對應（2026-08-25 決定）

匯入時順便把 OSM 的設施標籤轉成 `features.code`。對應與**每個標籤收哪些值**如下：

| features.code | OSM 標籤 | 收錄的值 |
| --- | --- | --- |
| `takeout` | `takeaway` | `yes`, `only` |
| `delivery` | `delivery` | `yes` |
| `outdoor_seating` | `outdoor_seating` | `yes`, `patio`, `veranda`, `terrace`, `garden`, `rooftop`, `sidewalk`, `street`, `pedestrian_zone` |
| `wifi` | `internet_access` | `wlan`, `yes` |
| `reservation` | `reservation` | `yes`, `required`, `recommended` |
| `pet_friendly` | `dog` | `yes`, `leashed` |

**值的白名單是重點，不能寫成「有這個標籤就算有這個特色」。** 實測台中 177 筆＋東京 210 筆的
標籤分布：`outdoor_seating=no` 有 32 筆、比 `yes` 的 10 筆還多，`wheelchair=no` 14 筆、
`delivery=no` 5 筆。只看 key 存在會把明確標示「沒有」的店標成「有」——把使用者騙去白跑一趟，
比漏收嚴重得多。

**沒有對應的兩個特色**：`parking` 與 `family_friendly` 在兩地共 387 筆節點裡是 **0 筆**
（含 `capacity:parking`／`kids_area` 等變體都查過）。OSM 對餐廳節點沒有通用的停車標註慣例。
寧可讓這兩個篩選維持空的，也不硬湊一個不成立的對應。

**尚未使用的訊號**：`wheelchair` 在兩地共 52 筆（東京 49、台中 3），是目前最豐富的
未使用標籤，但我們的 `features` 表沒有對應項目。要不要新增是產品決定。

同步用 `syncWithoutDetaching`：每天的自動同步只補充 OSM 知道的部分，不會洗掉 Admin 或
使用者手動加上的特色。diet 關聯不是這套——OSM 映射得到的 code 會整組替換，見上方
「標籤怎麼對到 diet_types.code」。

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
