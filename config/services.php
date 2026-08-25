<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    // VeggieMap 外部資料源設定，見 docs/external-apis.md。
    /*
    | 斷路器（見 App\Services\External\CircuitBreaker）。連續失敗達到門檻後，
    | 冷卻時間內的請求直接短路。門檻設 5：排程一次跑五個城市 bbox，Overpass 整個
    | 掛掉時第一輪就會達標，第二個城市起不再空等。
    */
    'circuit_breaker' => [
        'failure_threshold' => env('EXTERNAL_API_CIRCUIT_FAILURE_THRESHOLD', 5),
        'cooldown_seconds' => env('EXTERNAL_API_CIRCUIT_COOLDOWN_SECONDS', 600),
    ],

    'overpass' => [
        'url' => env('EXTERNAL_API_OVERPASS_URL', 'https://overpass-api.de/api/interpreter'),
        'timeout' => env('EXTERNAL_API_OVERPASS_TIMEOUT', 30),
        // 必要，不是禮貌性的：overpass-api.de 會用 HTTP 406 擋掉 Guzzle 預設 User-Agent
        // （2026-08-25 實測，見 docs/progress.md）。預設沿用 Nominatim 那組識別字串——
        // 同一個專案對同一家 OSM 服務族群，沒有理由用兩個身分。
        // 用 ?: 而不是 env() 的第二參數：.env 裡寫 `EXTERNAL_API_OVERPASS_USER_AGENT=`
        // 是「已定義的空字串」，env() 的預設值不會生效，會靜默送出空 UA 再被 406 擋掉。
        'user_agent' => env('EXTERNAL_API_OVERPASS_USER_AGENT') ?: env('EXTERNAL_API_NOMINATIM_USER_AGENT'),
    ],

    'nominatim' => [
        'url' => env('EXTERNAL_API_NOMINATIM_URL', 'https://nominatim.openstreetmap.org'),
        'user_agent' => env('EXTERNAL_API_NOMINATIM_USER_AGENT'),
    ],

    // mock｜osm，見 App\Providers\AppServiceProvider 的 RestaurantProviderInterface 綁定。
    'restaurant_provider' => env('EXTERNAL_API_RESTAURANT_PROVIDER', 'mock'),

    // routes/console.php 的排程要用。每組格式 "minLat,minLng,maxLat,maxLng@收錄規則"，
    // 多組用分號分隔；`@規則` 可省略，預設 only（對齊 config/diet.php 的 default_sync_mode）。
    // 規則名稱必須是 diet.sync_modes 的 key；未知名稱會在 OsmRestaurantProvider 建構時丟例外，
    // 不會默默當成 only。留空則完全不排程。
    //
    // 收錄規則名稱是 diet.sync_modes 的 key，跟著 bbox 走，不在這裡寫死國家。
    //   only — 只收 diet:*=only。
    //   yes  — diet:*=yes 與 only 都收（純素食店是友善集合的子集）。
    // 節點怎麼對到 diet_types.code（only→exclusive、yes→friendly）在 config/diet.php。
    // 哪個範圍用哪種模式寫在 EXTERNAL_API_SYNC_BBOXES（見 .env.example）。
    // 見 docs/external-apis.md「收錄規則」與 docs/todo.md。
    'sync_regions' => array_values(array_filter(array_map(
        static function (string $entry): ?array {
            $entry = trim($entry);

            if ($entry === '') {
                return null;
            }

            [$bbox, $diet] = array_pad(explode('@', $entry, 2), 2, null);

            return [
                'bbox' => trim($bbox),
                'diet' => $diet === null || trim($diet) === '' ? 'only' : trim($diet),
            ];
        },
        explode(';', (string) env('EXTERNAL_API_SYNC_BBOXES', ''))
    ))),

];
