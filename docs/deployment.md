# Deployment — VeggieMap (AWS)

Phase 13 產出。**這份文件只提供部署步驟，沒有實際執行過 production 部署**——沒有 AWS
credentials，也沒有使用者確認要真的花錢起 infra，依照總 prompt 第十三節規則先停在文件階段。

## 先讀這段：目前還不是 production-ready

在照下面步驟部署之前，先知道這些已知缺口（都記錄在 [docs/todo.md](todo.md)），
不是部署文件疏漏，是這個專案目前刻意還沒做的事：

| 缺口 | 影響 | 部署前要不要處理 |
|---|---|---|
| 沒有 Laravel Horizon／queue worker，所有 Job 用 `dispatchSync()` | Rating／confidence score 重算會拖長 request 時間（目前資料量小，感覺不出來；資料量大之後會變慢） | 建議處理，見下方「Queue Worker」 |
| 沒有 `users:promote` 指令 | 上線後沒有 UI／指令可以把某帳號設成 admin，只能連進 DB 手動改 | 至少要能連 production DB 執行一次 SQL |
| 沒有排程自動跑 `restaurants:sync`／批次計算 Job | 資料不會自動更新，需要人工執行 artisan 指令 | 視是否要自動化決定，見下方「排程」 |
| `composer audit` 的 `CVE-2026-48019`（email 驗證 CRLF injection） | Laravel 11.x 已知安全公告 | **部署前必須處理**，見下方「安全性」 |
| Nominatim 商業使用政策偏保留（見 [external-apis.md](external-apis.md)） | 公開營運的地址搜尋流量可能違反 Nominatim 使用政策 | 真的要公開營運，評估換付費 Geocoding 服務 |

## 架構選擇

兩個方案，依專案階段選：

### 方案 A：EC2 + RDS + ElastiCache（推薦，Portfolio Demo 用）

```
Route 53 → ACM(HTTPS) → EC2 (Docker: nginx + php-fpm + Vue build) → RDS MySQL 8.0
                                                                   → ElastiCache Redis
```

單一 EC2 instance 跑現有的 `docker-compose.yml`（去掉 `mysql`／`redis` 兩個 service，
指向 RDS／ElastiCache），成本低、設定簡單，適合履歷展示用的 Demo 環境。缺點是沒有
自動 scaling、單點故障。

**為什麼選這個而不是方案 B**：這是一個 Portfolio 專案，不是真的要撐流量的產品——
方案 B 的維運複雜度（ECS task definition、ALB target group、CloudWatch Logs 設定）
對「展示 Backend Engineer 系統設計能力」這個目標邊際效益不高，先把方案 A 走通，
之後真的需要 scaling 再升級到方案 B，不是一開始就過度工程化。

### 方案 B：ECS Fargate + RDS + ElastiCache（未來要 scale 時）

```
Route 53 → ALB(HTTPS via ACM) → ECS Fargate Service (app container)
                                → ECS Fargate Service (nginx container，或用 ALB 直接對 php-fpm)
                              → RDS MySQL 8.0 (Multi-AZ)
                              → ElastiCache Redis (cluster mode)
```

無伺服器管理、自動 scaling、跟 CI/CD（`.github/workflows/ci.yml`）接自動部署比較自然
（`docker build` → push 到 ECR → 更新 ECS service）。複雜度與成本都比方案 A 高，
本文件先不展開逐步操作，僅記錄架構方向。

---

## 方案 A 部署步驟

### 1. 佈建 RDS MySQL

- Engine：MySQL 8.0（跟本機 Docker 版本一致，避免 Spatial 函式行為差異）
- Instance class：`db.t4g.micro`（Demo 流量足夠）
- 建立 database `veggiemap`，記下 endpoint／帳密
- Security Group 只開放 EC2 instance 的 Security Group 存取 3306，不對外公開

### 2. 佈建 ElastiCache Redis

- Engine：Redis 7.x
- Node type：`cache.t4g.micro`
- 同一個 VPC，Security Group 只開放 EC2 存取 6379

### 3. 佈建 EC2

- Amazon Linux 2023 或 Ubuntu 22.04，安裝 Docker + Docker Compose
- Security Group：對外開 80/443（給 ALB 或直接對外時），只信任的 IP 開 22（SSH）
- Elastic IP，Route 53 A record 指過去

### 4. 修改 `.env`（不要沿用 `docker-compose.yml` 的本機服務名稱）

```bash
APP_ENV=production
APP_DEBUG=false                    # 本機 .env.example 預設 true，production 必須改 false
APP_URL=https://veggiemap.example.com

DB_HOST=<RDS endpoint>
DB_PASSWORD=<RDS 密碼，用 AWS Secrets Manager 或 SSM Parameter Store 存，不要寫死進版控>

REDIS_HOST=<ElastiCache endpoint>

CORS_ALLOWED_ORIGINS=https://veggiemap.example.com   # 不能沿用 localhost:5173

LOG_LEVEL=warning                  # 本機預設 debug，production 會把 log 灌爆
EXTERNAL_API_RESTAURANT_PROVIDER=osm   # 本機預設 mock，production demo 才有意義用真資料
```

`.env` 本身**不要**進版控（`.gitignore` 已排除）；正式做法是用 AWS Secrets Manager 或
SSM Parameter Store 存敏感值，部署時動態產生 `.env`，不要把密碼明文放在 EC2 磁碟上的
一般檔案。

### 5. 建置與啟動

```bash
git clone https://github.com/thothawei/veggie-map.git
cd veggie-map
cp .env.example .env   # 再依上面清單覆寫成 production 值
docker compose -f docker-compose.yml up -d --build app nginx   # 不啟動本機 mysql/redis service
docker compose exec app composer install --no-dev --optimize-autoloader
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate --force
npm ci && npm run build   # 前端資產（見 Phase 12 CI 抓到的教訓：/ 需要 Vite manifest）
docker compose exec app php artisan config:cache
docker compose exec app php artisan route:cache
```

`docker-compose.yml` 目前的 `mysql`／`redis` service 定義在 production 下不會被用到
（`.env` 已指向 RDS／ElastiCache），保留它們只是為了本機開發方便，不需要為了部署另外
維護一份 production 專用 compose 檔——只要不啟動這兩個 service 即可。

### 6. HTTPS

用 AWS Certificate Manager 簽發憑證，放在 ALB（如果加了 ALB）或直接在 EC2 上用
nginx + Let's Encrypt（`certbot`）。這份文件不展開逐步操作，因為取決於是否引入 ALB。

### 7. 建立第一個 Admin 帳號

目前沒有 `users:promote` 指令（見上方缺口清單），部署後第一次要手動：

```bash
docker compose exec app php artisan tinker --execute="
App\Models\User::where('email', 'you@example.com')->update(['role' => 'admin']);
"
```

先透過 `POST /api/v1/auth/register` 註冊一個帳號，再執行上面指令升級。

### 8. Queue Worker（建議處理，非必要）

現況：`CalculateRestaurantScoreJob`／`RecalculateRestaurantRatingJob` 用 `dispatchSync()`
同步跑（見 [docs/progress.md](progress.md) Phase 6 的決定）。部署到 production 前建議
評估是否要：

1. 安裝 Laravel Horizon，起一個 supervisor 管理的 `php artisan horizon` process
2. 把 `dispatchSync()` 改回 `dispatch()`

如果流量小（Portfolio Demo 等級），維持 `dispatchSync()` 也能正常運作，只是每次
送出評論／匯入資料時 request 會多花一點時間，不是錯誤，是已知的效能取捨。

### 9. 排程（選用）

`restaurants:sync`／`restaurants:recalculate-ratings`／`restaurants:calculate-scores`
目前都要手動執行。如果要自動化，在 EC2 上用系統 `cron` 呼叫（不是 Laravel 的
`routes/console.php` schedule，因為那需要 `php artisan schedule:run` 本身被排程，
兩者最終都要落到系統層級的 cron）：

```cron
0 3 * * * cd /path/to/veggie-map && docker compose exec -T app php artisan restaurants:calculate-scores
0 4 * * * cd /path/to/veggie-map && docker compose exec -T app php artisan restaurants:recalculate-ratings
```

`restaurants:sync` 需要 `--bbox` 參數（見 [docs/api.md](api.md)），不適合無腦排程——
要嘛固定一組城市的 bbox 清單跑，要嘛先不排程，維持手動執行。

## 安全性（部署前必須處理）

- **`CVE-2026-48019`**（`composer audit` 顯示的 Laravel 11.x email 驗證 CRLF injection
  公告）：官方修法是升級到 Laravel 12.60+/13.10+，或在 FormRequest layer 額外處理。
  這個專案的 User 註冊／Report 表單都用到 email 驗證，**部署前必須解決**，不是「之後
  有空再說」的技術債。
- `APP_DEBUG=false`：本機 `.env.example` 預設 `true`，忘記改會把完整 stack trace 洩漏給
  使用者。
- `.env` 不進版控、不寫死密碼——見上方「修改 `.env`」段落。
- Rate limiting：目前只有 Laravel 預設值，公開營運前應該針對 `/api/v1/restaurants`
  這種允許匿名存取的端點額外評估限流閾值（見 [docs/architecture.md](architecture.md)
  的 Redis-based rate limiter 設計）。

## 回滾

沒有藍綠部署或自動回滾機制。目前的部署方式是「SSH 進 EC2、`git pull`、重新 `docker
compose up -d --build`」，回滾就是 `git checkout <previous-commit>` 再重跑一次同樣的
建置步驟。真的要做零停機部署／自動回滾，需要方案 B（ECS）的 rolling deployment，
不是這份文件範圍。
