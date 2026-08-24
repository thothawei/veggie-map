#!/usr/bin/env bash
# 建立/確保 veggiemap_testing 測試資料庫存在，並跑 migration。
#
# 為什麼需要這支腳本：PHPUnit 的 Feature test 用真的 MySQL（不是 sqlite in-memory）——
# schema 用了 POINT／ST_Distance_Sphere／MBRContains 等 MySQL 專屬空間函式，sqlite 跑不起來
# （見 docs/progress.md「Feature Test 補完」）。docker/mysql/init/ 底下的 SQL 只有在全新 volume
# 第一次啟動時才會被 MySQL 官方 image 執行，本機既有的 volume 不會自動重跑，所以需要這支
# 隨時可以重跑的等效版本，也是 CI 之後要接的那一步（見 docs/todo.md Phase 10/12）。
set -euo pipefail

cd "$(dirname "$0")/.."

echo "==> 建立 veggiemap_testing 資料庫（若不存在）..."
docker compose exec -T mysql mysql -uroot -pveggiemap_root -e "
    CREATE DATABASE IF NOT EXISTS veggiemap_testing;
    GRANT ALL PRIVILEGES ON veggiemap_testing.* TO 'veggiemap'@'%';
    FLUSH PRIVILEGES;
"

echo "==> 執行 migration（不含 seed，測試資料由各個 Feature test 自己用 factory 建立）..."
docker compose exec -T -e DB_DATABASE=veggiemap_testing app php artisan migrate --force

echo "==> 完成，veggiemap_testing 已就緒。"
