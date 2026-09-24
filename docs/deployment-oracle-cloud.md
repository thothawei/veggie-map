# Deployment — Oracle Cloud Free Tier（零成本方案）

這份文件跟 [deployment.md](deployment.md)（AWS 方案）一樣：**只提供部署步驟，沒有實際
執行過**——沒有 OCI 帳號的實測環境，依照同樣的規則先停在文件階段。跟 AWS 版最大的差異
是目標不同：AWS 版是「Portfolio Demo，成本低但仍要付費」，這份是**完全零花費**——用
Oracle Cloud 的 Always Free 額度（永久免費，不是 12 個月試用到期就收費那種），直接把
現有的 `docker-compose.yml` 整套（含 MySQL、Redis）搬上去跑，不依賴任何要付費的雲端服務。

## 先讀這段：跟 AWS 版共用的已知缺口

[deployment.md](deployment.md) 開頭那張「已知缺口」表（Nominatim 使用政策、Horizon／
Telescope 白名單、Sanctum token 不過期、沒有效能追蹤）在這裡一樣成立，不重複貼一次，
部署前先看那份文件的對應段落。

這份文件另外多兩個 Oracle Free Tier 特有的、**部署前必須知道**的限制：

| 限制 | 說明 | 影響 |
|---|---|---|
| Always Free VM 是 **ARM（Ampere A1）** 架構 | 免費額度給的是 ARM CPU，不是 x86 | Docker image 要有 `arm64` 版本。MySQL 8、Redis、nginx、PHP 官方 image 都有 arm64 build，理論上直接能跑，但**這份文件沒有實測過**，第一次 `docker compose up` 建議留時間排查 image 相容性問題 |
| Always Free 容量常常「Out of Capacity」 | Oracle 這個免費方案很多人在搶，尤其 ARM Ampere A1，熱門區域常常建立時噴 `Out of host capacity` | 換一個 Region／Availability Domain 再試，或用官方提供的重試腳本，不是帳號或設定有問題 |

## 架構

```
使用者 ── HTTPS ──▶ Cloudflare（可選，見下方兩種網域方案）
                          │
                          ▼
                Oracle Cloud VM（Ampere A1，Always Free）
                ┌─────────────────────────────┐
                │ docker compose：             │
                │  nginx → app(php-fpm) → mysql │
                │                        → redis│
                │  horizon（queue worker）       │
                │  scheduler（cron）             │
                └─────────────────────────────┘
```

跟 AWS 方案（EC2 + RDS + ElastiCache）不同，這裡**不拆外部託管資料庫**——Oracle 的
免費資料庫服務是 Autonomous Database（Oracle DB，不是 MySQL），要接上去得改
`config/database.php` 的 driver 並處理 SQL 方言差異（尤其這個專案大量用到 MySQL 的
`ST_Distance_Sphere` 等 Spatial 函式，見 [architecture.md](architecture.md)），
不值得為了省一台 VM 的資源去換資料庫引擎。維持 `docker-compose.yml` 原本的
`mysql`／`redis` service 直接在同一台 VM 上跑，跟本機開發環境架構一致，這也是選這個
方案而不是「VM 只跑 app，資料庫用 Oracle 雲端服務」的原因。

## 網域與 HTTPS：兩個方案

Oracle Free Tier 不含網域名稱，网域本身原生就不是免費資源（除非你已經有）。兩個路徑：

### 方案 A：沒有網域 → `sslip.io` 萬用網域 + Let's Encrypt（完全免費，不用買網域）

`sslip.io`／`nip.io` 這類服務把 IP 位址編碼進網域名稱（例如 VM 的公網 IP 是
`140.1.2.3`，網域就是 `140-1-2-3.sslip.io`），DNS 直接解回那個 IP，不用自己管理 DNS
record。Let's Encrypt 認這種網域，可以正常簽發真的 HTTPS 憑證。

缺點：網址長得不好看、不好分享，而且**VM 的公網 IP 是直接暴露的**（沒有 CDN／反向代理
擋在前面），只靠 nginx 自己扛流量與基本的 DDoS。適合先求「真的有一個公開網址能用」的
Demo 階段。

### 方案 B：有網域（或願意買一個便宜的）→ Cloudflare Tunnel（免費，IP 不外露）

Cloudflare 的網域註冊本身不是免費的，但**如果你已經有網域，或願意花小錢買一個**
（多數 TLD 一年幾十到幾百元台幣），接上 Cloudflare 之後：

- Cloudflare Tunnel（`cloudflared`）免費，VM 完全不用對外開 80/443，也不需要固定公網
  IP——由 `cloudflared` 主動連出去建立隧道，比方案 A 安全。
- Cloudflare 的 SSL/TLS 是免費的，不用自己管 Let's Encrypt 憑證續期。
- 附帶 CDN 快取與基本 DDoS 防護。

**判斷**：如果目的只是「先有個網址能展示、能讓 iPhone 裝 PWA」，方案 A 更快、真正
零成本，先走方案 A；之後真的要長期公開營運、在意安全性，再換方案 B。下面步驟兩個都寫，
挑一個做即可（互斥，不要兩個同時設定）。

---

## 部署步驟

### 1. 申請 OCI 帳號、建立 Compute VM

1. 到 Oracle Cloud 官網註冊 Always Free 帳號（需要信用卡驗證身分，但 Always Free
   資源本身不會被扣款，除非你自己手動升級成付費帳戶）。
2. Compute → Instances → Create Instance：
   - Image：Ubuntu 22.04（或 24.04）**ARM 版本**
   - Shape：`VM.Standard.A1.Flex`，選 Always Free 額度上限（4 OCPU／24 GB RAM，
     或依需求拆成多台較小的 VM，Always Free 總額度是共用的）
   - 勾選「Assign a public IPv4 address」
   - 產生或上傳一組 SSH key，之後用它登入
3. 建立時如果遇到 `Out of host capacity`：換一個 Availability Domain 或 Region 再試
   （見上方「已知限制」）。
4. **把公網 IP 保留成 Reserved Public IP**（Networking → IP Management），不要用
   VM 預設的臨時公網 IP——臨時 IP 在 VM 重啟後可能換掉，會導致方案 A 的 `sslip.io`
   網址、方案 B 的 DNS record，甚至 PWA 已經裝到 iPhone 桌面的那個 origin 全部失效
   （PWA 是綁 origin 的，換網域等於使用者要重新加入主畫面一次）。

### 2. 開防火牆

OCI 有兩層防火牆，兩層都要開，缺一層仍然連不進去：

- **OCI 層（Security List／NSG）**：VM 所在的 Subnet → Security List，新增 Ingress
  Rule 開放 TCP 80、443（來源 `0.0.0.0/0`）。SSH 用的 22 port 建議只開放自己的固定
  IP，不要對全世界開放。
- **VM 系統層（iptables／ufw）**：Ubuntu 的 OCI 官方 image 預設會啟用 `iptables`
  擋掉沒放行的 port，即使 OCI 那層已經開了，VM 裡面沒放行一樣連不進去。

```bash
sudo ufw allow 80/tcp
sudo ufw allow 443/tcp
sudo ufw allow OpenSSH
sudo ufw enable
```

### 3. 安裝 Docker + Docker Compose

```bash
curl -fsSL https://get.docker.com | sudo sh
sudo usermod -aG docker $USER
newgrp docker
docker compose version   # 確認是 Compose v2（跟本機開發版本一致）
```

### 4. Clone 專案、設定 `.env`

```bash
git clone https://github.com/thothawei/veggie-map.git
cd veggie-map
cp .env.example .env
```

跟 AWS 版最大的不同：`DB_HOST`／`REDIS_HOST` **不用改**，繼續指向
`docker-compose.yml` 裡的 `mysql`／`redis` service name（跟本機開發環境一樣），因為
資料庫就跑在同一台 VM 上，不是外部託管服務。要改的是：

```bash
APP_ENV=production
APP_DEBUG=false                    # 本機 .env.example 預設 true，忘記改會洩漏 stack trace
APP_URL=https://<你的網域或 IP-sslip.io 網址>

DB_PASSWORD=<自己設一組，不要用 .env.example 的預設值>

CORS_ALLOWED_ORIGINS=https://<你的網域或 IP-sslip.io 網址>   # 不能沿用 localhost:5173

LOG_LEVEL=warning                  # 本機預設 debug，production 會把 log 灌爆
EXTERNAL_API_RESTAURANT_PROVIDER=osm   # 本機預設 mock，production demo 才有意義用真資料
```

### 5. 建置與啟動

```bash
docker compose up -d --build
docker compose exec app composer install --no-dev --optimize-autoloader
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate --force

# 前端資產（PWA 的 manifest／service worker 也是這一步一起產出，見 vite.config.js）
npm ci
npm run build

docker compose exec app php artisan config:cache
docker compose exec app php artisan route:cache
```

### 6a. 方案 A：`sslip.io` + Let's Encrypt（Nginx 直接對外）

```bash
sudo apt install -y certbot python3-certbot-nginx
# <PUBLIC_IP> 換成第 1 步保留下來的 Reserved Public IP，把點換成減號
sudo certbot --nginx -d <PUBLIC_IP 減號版>.sslip.io
```

`certbot` 的 nginx 外掛會自動改 `docker/nginx/default.conf` 對應的 site 設定並
掛上自動續期（Let's Encrypt 憑證 90 天到期，`certbot` 裝的時候會順便建好續期的
systemd timer／cron，不用自己另外設）。**這裡假設 certbot 直接裝在 host 上管理宿主
機的 nginx，不是 docker-compose 裡那個 nginx container**——如果你要簽給 container
內的 nginx，改用 `certbot certonly --standalone`（跑之前先停掉 nginx container 讓
80 port 空出來）拿到憑證檔案，再把憑證路徑掛進 `docker-compose.yml` 的 volume，
`docker/nginx/default.conf` 加上 `listen 443 ssl` 與憑證路徑設定。

### 6b. 方案 B：Cloudflare Tunnel（不開 80/443）

前提：網域已經加進 Cloudflare 帳號、Nameserver 已經指過去。

```bash
# 安裝 cloudflared（ARM64 版）
curl -L https://github.com/cloudflare/cloudflared/releases/latest/download/cloudflared-linux-arm64 -o cloudflared
chmod +x cloudflared
sudo mv cloudflared /usr/local/bin/

cloudflared tunnel login                       # 瀏覽器登入 Cloudflare 帳號授權
cloudflared tunnel create veggiemap
cloudflared tunnel route dns veggiemap veggiemap.example.com   # 換成你的網域
```

建立 `~/.cloudflared/config.yml`：

```yaml
tunnel: veggiemap
credentials-file: /home/ubuntu/.cloudflared/<tunnel-id>.json

ingress:
  - hostname: veggiemap.example.com
    service: http://localhost:80
  - service: http_status:404
```

```bash
sudo cloudflared service install
sudo systemctl enable --now cloudflared
```

這個方案下 OCI Security List 的 80/443 Ingress Rule 可以整條拿掉——對外流量全部
經過 Cloudflare 的隧道進來，VM 本身不需要對公網開任何 port（除了自己 SSH 用的來源
限定規則）。

### 7. 開機自動啟動

```bash
sudo tee /etc/systemd/system/veggiemap.service <<'EOF'
[Unit]
Description=VeggieMap docker compose
Requires=docker.service
After=docker.service

[Service]
Type=oneshot
RemainAfterExit=yes
WorkingDirectory=/home/ubuntu/veggie-map
ExecStart=/usr/bin/docker compose up -d
ExecStop=/usr/bin/docker compose down

[Install]
WantedBy=multi-user.target
EOF

sudo systemctl enable veggiemap.service
```

### 8. 建立第一個 Admin 帳號

跟 AWS 版一樣：先透過 `POST /api/v1/auth/register` 註冊一個帳號，再用指令升級：

```bash
docker compose exec app php artisan users:promote you@example.com
```

### 9. Queue Worker 與排程

`docker-compose.yml` 已經有 `horizon` service，`docker compose up -d` 會一起啟動，
不用另外設定。`/horizon`、`/telescope` 的白名單一樣看
`DASHBOARD_ALLOWED_EMAILS`（見 [deployment.md](deployment.md) 對應段落，這裡不重複）。

排程用系統 crontab，跟 AWS 版一樣：

```cron
0 3 * * * cd /home/ubuntu/veggie-map && docker compose exec -T app php artisan restaurants:calculate-scores
0 4 * * * cd /home/ubuntu/veggie-map && docker compose exec -T app php artisan restaurants:recalculate-ratings
```

## 安全性（部署前必須處理）

跟 [deployment.md](deployment.md) 共用的部分（`composer audit`、`APP_DEBUG=false`、
`.env` 不進版控、Rate limiting 尚待評估）不重複列。Oracle 版額外要注意：

- **方案 A（`sslip.io`）等於把 VM 的公網 IP 直接攤在网址列**，掃描器很容易對上 IP
  找到這台機器。SSH 一定要限制來源 IP，且優先用 key-based auth，關掉密碼登入。
- **`ufw`／Security List 的規則兩層都要記得開回去比對**：改了其中一層卻忘了另一層，
  最常見的症狀是「本機 `curl localhost` 正常，但外部連不進去」，先查是哪一層擋住，
  不要假設一定是應用程式的問題。

## 已知限制 / 未驗證事項（誠實列出，不是藏起來）

- **ARM64 image 相容性沒有實測過**：`docker-compose.yml` 目前用的 base image（PHP、
  MySQL、Redis、nginx）理論上都有官方 arm64 build，但這份文件沒有真的在 Ampere A1
  上跑過 `docker compose up --build` 驗證。第一次部署遇到 `no matching manifest for
  linux/arm64` 這類錯誤，是預期內可能發生的事，不是設定寫錯。
- **Always Free 的算力（4 OCPU ARM）跟 AWS 方案的 `db.t4g.micro`／`cache.t4g.micro`
  規模不是對等比較**，沒有實測過這個專案的搜尋、地理空間查詢在 Ampere A1 上的實際
  reponse time，不能直接沿用 [database.md](database.md) 裡 AMD/Intel 環境量出來的
  p50/p95 數字。

## 回滾

跟 AWS 版一樣，沒有藍綠部署：

```bash
git checkout <previous-commit>
docker compose up -d --build
docker compose exec app php artisan migrate --force   # 如果有新 migration 需要對應回滾，先確認
npm ci && npm run build
```
