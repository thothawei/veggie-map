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
    ],

    'nominatim' => [
        'url' => env('EXTERNAL_API_NOMINATIM_URL', 'https://nominatim.openstreetmap.org'),
        'user_agent' => env('EXTERNAL_API_NOMINATIM_USER_AGENT'),
    ],

    // mock｜osm，見 App\Providers\AppServiceProvider 的 RestaurantProviderInterface 綁定。
    'restaurant_provider' => env('EXTERNAL_API_RESTAURANT_PROVIDER', 'mock'),

    // routes/console.php 的排程要用，逗號分隔多組 "minLat,minLng,maxLat,maxLng"（用分號分隔
    // 多組 bbox）。沒有預設值——這個專案沒有正式決定過要自動涵蓋哪些城市範圍，寧可不排程
    // 也不要自己編一組座標假裝是產品決策，見 docs/todo.md。
    'sync_bboxes' => array_values(array_filter(array_map(
        'trim',
        explode(';', (string) env('EXTERNAL_API_SYNC_BBOXES', ''))
    ))),

];
