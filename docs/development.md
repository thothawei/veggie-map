# Development — 本機開發指南

> 對應 AI Office 規格 §60～§62、§64、§73。README 有精簡版的啟動步驟；
> 這份是「每天工作時會用到的東西」：指令、慣例、以及這個 repo 特有的坑。

## 啟動

```bash
cp .env.example .env
docker compose up -d --build
docker compose exec app composer install
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate --seed
npm install && npm run dev        # 前端跑在 host 上，不在容器裡
```

`http://localhost:8080/` 是完整 SPA，API 在 `http://localhost:8080/api/v1`，
可瀏覽的 API 文件在 `http://localhost:8080/docs`（Redoc；由 `veggiemap.docs.enabled` 控制，
production 預設關閉）。

六個服務：`app`（PHP 8.2-fpm）、`horizon`（同一個 image 改跑 `php artisan horizon`）、
`scheduler`（`schedule:work`）、`nginx`、`mysql`、`redis`。
本機 3306／80 被佔用時 host 對外映射是 3307／8080，容器內部 port 不變。

## 測試

```bash
./scripts/setup-test-db.sh                    # 第一次跑測試前執行一次（冪等，隨時可重跑）
docker compose exec app php artisan test      # 後端
npm run test                                  # 前端 Vitest
npm run type-check                            # vue-tsc --noEmit
npm run lint                                  # ESLint
docker compose exec app ./vendor/bin/pint     # 格式化（--test 只檢查不改）
docker compose exec app ./vendor/bin/phpstan analyse
```

測試數量以 CI 為準（README 裡的數字會過期，[ci.yml](../.github/workflows/ci.yml) 不會）。

三件跟別的 Laravel 專案不同的事：

1. **測試用真的 MySQL，不是 sqlite in-memory**。schema 用了 `POINT`／
   `ST_Distance_Sphere`／`MBRContains` 這些 MySQL 專屬空間函式，sqlite 跑不起來。
   全新 volume 會由 `docker/mysql/init/01-create-test-database.sql` 自動建測試庫，
   但既有的舊 volume 不會重跑那支 init script——那正是 `scripts/setup-test-db.sh` 存在的理由。
2. **多個 Claude Code／終端機同時跑 `php artisan test` 會互相干擾**：共用同一個
   `veggiemap_testing` 資料庫，會出現隨機的「table doesn't exist」。
   那是環境限制，不是程式碼有併發 bug——重跑前先確認沒有另一個 session 在跑。
3. **AI Office 的沙箱整合測試需要 docker CLI**。app container 裡沒有，所以本機是 skip；
   CI 的 ubuntu runner 有，所以在 CI 會真的把容器跑起來。本機全綠不代表那四條驗過了。

## 常用 artisan 指令

| 指令 | 用途 |
|---|---|
| `restaurants:sync --bbox="minLat,minLng,maxLat,maxLng" [--provider=osm] [--diet=only]` | 從外部來源同步餐廳。**bbox 必填**——一次只查一個小範圍，不要撈全台灣 |
| `restaurants:calculate-scores` | 重算素食可信度（每日排程） |
| `restaurants:recalculate-ratings` | 重算評分快取欄位（每日排程） |
| `restaurants:reparse-opening-hours [--dry-run]` | 解析器改版後，用既有字串重新產生時段列 |
| `restaurants:backfill-slugs [--force]` | 舊 slug 換拼音，舊網址留成別名。**預設 dry-run** |
| `cache:stats [--day=YYYY-MM-DD]` | 看 cache hit／miss |
| `users:promote {email}` | 把使用者升為 admin |
| `ai-office:demo [--fresh] [--reject]` | 跑完整的多 Agent Demo：規劃→派工→工具寫檔→撞核准停下→人核准→完成 |

排程（`routes/console.php`）：評分與可信度每日重算，各城市的 `restaurants:sync`
錯開時段每日執行。

## 前端

Vue 3 + TypeScript + Pinia + Vue Router + Leaflet，原始碼在 `resources/js/`，
AI Office 的部分在 `resources/js/ai-office/`（元件／stores／api 各自分開）。

**改完 `resources/js` 一定要 `npm run build` 再去瀏覽器驗收**——`localhost:8080`
讀的是 `public/build` 裡的資產，不 build 就會看到舊行為，然後花半小時 debug 一個
已經改好的問題。（`npm run dev` 開著時走 Vite dev server，不受影響。）

`npm run build` 內含 `vue-tsc --noEmit`：型別錯誤會讓 build 直接失敗，不會產出資產。

## 程式碼慣例

- **後端**：Controller 不放商業邏輯（進 Service），不直接查 DB（進 Repository），
  驗證進 FormRequest，回應進 API Resource。第三方 API 一律走
  `app/Services/External/` 的 Adapter，不在 Controller 裡 `Http::get()`。
- **設定不寫死**：權重、白名單、風險等級、收錄規則都放 `config/`
  （`vegetarian.php`／`recommendation.php`／`diet.php`／`ai_office.php`）。
  在 Controller 裡看到 `if ($type === 'vegan')` 這種判斷就是走錯方向了。
- **註解寫「為什麼」**：程式碼本身說得清楚的事不用重複，寫下的是約束、踩過的坑、
  以及為什麼不選另一條路。
- **Commit**：`feat:`／`fix:`／`refactor:`／`test:`／`docs:`／`chore:`。

## CI

[`.github/workflows/ci.yml`](../.github/workflows/ci.yml) 三個 job：

| job | 內容 |
|---|---|
| `backend` | Pint（`--test`）→ PHPStan → migrate → `php artisan test`，配 MySQL 與 Redis service |
| `frontend` | ESLint → vue-tsc → Vitest → build |
| `docker` | compose 設定驗證 → build `docker/php/Dockerfile` → 驗證映像裡八個 PHP 擴充與 composer 都在 |

`docker` job 補的是一個具體的洞：另外兩個 job 跑的是 runner 上的 PHP 與 Node，
碰不到 Dockerfile——在它之前，Dockerfile 或 compose 壞掉 CI 完全不會紅，
只有下一個 `docker compose up -d` 的人會踩到。

**接手時先看一眼 `gh run list`**：曾經發生過 CI 紅了兩天沒人發現（真因是一條依賴
機器時區的測試，以及兩條假設「這台機器沒有 docker」的沙箱測試）。

## 遇到問題時

先讀 [progress.md](progress.md)——每一則都寫「症狀 → 真因 → 為什麼這樣修」，
包含好幾次反向驗證抓到自己寫的假保護。剩餘規劃在 [todo.md](todo.md)。

## 相關文件

- 架構與技術選型的 Why：[architecture.md](architecture.md)
- 資料庫與索引：[database.md](database.md)｜API：[api.md](api.md)
- Agent／工具／安全：[agents.md](agents.md)｜[tools.md](tools.md)｜[security.md](security.md)
- 部署：[deployment.md](deployment.md)｜監控：[observability.md](observability.md)
