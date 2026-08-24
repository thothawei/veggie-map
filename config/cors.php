<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Bearer token 認證（非 Sanctum SPA cookie 模式，見 docs/progress.md Phase 4），
    | 所以 supports_credentials 不需要開。前端 dev server 跑在 5173，API 跑在 8080，
    | 跨 origin 必須明確允許，否則瀏覽器直接擋掉 fetch/axios 請求。
    |
    */

    'paths' => ['api/*'],

    'allowed_methods' => ['*'],

    'allowed_origins' => explode(',', env('CORS_ALLOWED_ORIGINS', 'http://localhost:5173')),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];
