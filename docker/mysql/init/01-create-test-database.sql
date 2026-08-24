-- 只有在 MySQL volume 第一次初始化時（全新 volume）才會被 docker-entrypoint 執行。
-- 已經存在的舊 volume（多數本機開發環境）不會自動重跑這個檔案，見
-- scripts/setup-test-db.sh 這個隨時可以重跑的等效版本。
CREATE DATABASE IF NOT EXISTS veggiemap_testing;
GRANT ALL PRIVILEGES ON veggiemap_testing.* TO 'veggiemap'@'%';
FLUSH PRIVILEGES;
