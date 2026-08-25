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
    // 多組用分號分隔；`@規則` 可省略，預設 only。留空則完全不排程。
    //
    // 收錄規則依國別而異，因為 OSM 的標籤慣例不同（2026-08-25 實測）：
    //   only — 只收整間店都是素／純素的（diet:*=only）。台灣適用：台中市 177/220 家是 only。
    //   yes  — 連「有素食選項」的一般餐廳一起收（diet:*=yes|only）。日本適用：東京 23 区
    //          只有 46/210 家標 only，日本社群慣用 yes，套 only 會讓地圖薄到不可用。
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
