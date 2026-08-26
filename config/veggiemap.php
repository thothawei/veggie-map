<?php

return [

    /*
    |--------------------------------------------------------------------------
    | 預設時區
    |--------------------------------------------------------------------------
    |
    | 座標對不上任何 config/cities.php 的 bbox 時，`open_now` 用這個時區判斷。
    | APP_TIMEZONE 是 UTC（Laravel 預設，log 用），不能拿來當餐廳的當地時間。
    |
    */

    'default_timezone' => env('VEGGIEMAP_DEFAULT_TIMEZONE', 'Asia/Taipei'),

    /*
    |--------------------------------------------------------------------------
    | 搜尋
    |--------------------------------------------------------------------------
    |
    | keyword_min_length：短於這個長度的詞不進 LIKE（單一個「素」會掃回幾乎整張表，
    | 相關性排序也失去意義）。CJK 沒有空白斷詞，所以中日文的門檻分開設。
    |
    */

    /*
    |--------------------------------------------------------------------------
    | 可觀測性
    |--------------------------------------------------------------------------
    |
    | slow_request_ms：超過這個毫秒數的 API 請求會寫一筆 warning log。
    | 每一筆都記等於自製一個沒人看的 APM，所以只記慢的；每一筆仍然會在回應帶
    | X-Response-Time-Ms 標頭。
    |
    */

    'observability' => [
        'slow_request_ms' => env('VEGGIEMAP_SLOW_REQUEST_MS', 1000),

        /*
        | 超過這個毫秒數的 SQL 會寫一筆 warning log（含 route，但**不含 bindings**
        | ——那裡面有使用者打的關鍵字與座標）。設 0 以下＝整個關掉。
        */
        'slow_query_ms' => env('VEGGIEMAP_SLOW_QUERY_MS', 200),

        /*
        | Cache 命中率統計。每次 hit／miss 會多一次 Redis 寫入，量很大時可以關掉。
        */
        'cache_stats' => (bool) env('VEGGIEMAP_CACHE_STATS', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | API 文件頁（/docs）
    |--------------------------------------------------------------------------
    |
    | 預設只在非 production 開。規格本身是公開的，關掉不是為了保密，而是不要在
    | 正式站放一個沒有人維護、只會誤導的頁面。要開就設 VEGGIEMAP_DOCS_ENABLED=true。
    |
    */

    'docs' => [
        'enabled' => (bool) env('VEGGIEMAP_DOCS_ENABLED', env('APP_ENV', 'production') !== 'production'),
    ],

    /*
    |--------------------------------------------------------------------------
    | 儀表板存取（Horizon／Telescope）
    |--------------------------------------------------------------------------
    |
    | 誰能在非 local 環境開 `/horizon`／`/telescope`。以逗號分隔的 email 清單，
    | **預設空的＝沒有人**——這兩個頁面看得到佇列內容、SQL 與請求內文，開錯人等於
    | 把整個系統的內部狀態送出去。要用就在部署環境設 DASHBOARD_ALLOWED_EMAILS。
    |
    | 刻意不寫死在程式碼裡：這個 repo 是公開的，把個人 email commit 進去等於公開
    | 一個聯絡方式，而且換人維護要改程式碼重新部署才生效。
    |
    */

    'dashboards' => [
        'allowed_emails' => array_values(array_filter(array_map(
            trim(...),
            explode(',', (string) env('DASHBOARD_ALLOWED_EMAILS', '')),
        ), fn (string $email): bool => $email !== '')),
    ],

    'search' => [
        'keyword_max_terms' => 5,
        'keyword_min_length' => 2,
        'keyword_min_length_cjk' => 1,

        /*
        | 搜尋建議的每分鐘上限。自動完成每打幾個字就是一次請求，跟一般 API 共用
        | 60/分鐘的話，正常打字幾輪就會撞 429、建議整個消失。
        */
        'suggest_rate_limit' => env('VEGGIEMAP_SUGGEST_RATE_LIMIT', 180),
    ],

];
