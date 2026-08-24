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

];
