<?php

/**
 * 獨立小腳本，只給 tests/Feature/ReviewServiceConcurrencyTest.php 當背景 process 用。
 *
 * 目的：在自己的交易裡執行跟 ReviewService::submit() 完全一樣的第一步
 * （UPDATE ... WHERE user_id/restaurant_id/status='active'），刻意在 commit 前 sleep，
 * 藉此撐住 InnoDB 在 (user_id, restaurant_id, status) 這個 index range 上取得的
 * next-key lock，讓主測試程序真的撞到鎖、真的等待，而不是「循序執行、根本沒重疊」
 * 的假並行測試。
 *
 * 不能用 PHPUnit 的 RefreshDatabase（它把整個測試包在一個交易裡，其他連線看不到
 * 未 commit 的資料），所以這裡故意繞開 Laravel、直接用原生 PDO 接同一個測試資料庫。
 *
 * Usage: php hold_review_lock.php <user_id> <restaurant_id> <rating> <sleep_seconds>
 */
[, $userId, $restaurantId, $rating, $sleepSeconds] = $argv;

$pdo = new PDO(
    sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
        getenv('DB_HOST') ?: 'mysql',
        getenv('DB_PORT') ?: '3306',
        getenv('DB_DATABASE') ?: 'veggiemap_testing',
    ),
    getenv('DB_USERNAME') ?: 'veggiemap',
    getenv('DB_PASSWORD') ?: 'veggiemap',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
);

// 兩邊交易對同一個空 index range 各自取得 gap lock 後都想 INSERT 進那個 gap，
// InnoDB 有機會判定成 deadlock（1213）直接讓其中一邊出局，不是誰乖乖排隊等誰
// ——真的讓兩個交易重疊後才發現的，見 ReviewService::submit() 現在用
// DB::transaction($fn, 3) 自動重試的理由。這裡也要重試，否則單純是背景腳本自己
// 沒做容錯，不代表 ReviewService 本身有沒有處理好。
$maxAttempts = 5;

for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
    try {
        $pdo->beginTransaction();

        $pdo->prepare(
            'UPDATE reviews SET status = "hidden" WHERE user_id = ? AND restaurant_id = ? AND status = "active"'
        )->execute([$userId, $restaurantId]);

        usleep((int) ((float) $sleepSeconds * 1_000_000));

        $pdo->prepare(
            'INSERT INTO reviews (user_id, restaurant_id, rating, comment, status, created_at, updated_at)
             VALUES (?, ?, ?, ?, "active", NOW(), NOW())'
        )->execute([$userId, $restaurantId, $rating, 'background process']);

        $pdo->commit();
        break;
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        if ($attempt === $maxAttempts || ! str_contains($e->getMessage(), 'Deadlock')) {
            throw $e;
        }
    }
}

echo 'done';
