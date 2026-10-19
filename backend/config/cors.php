<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => array_values(array_filter(array_unique([
        env('FRONTEND_URL'),
        'https://hotelsystem.osus.network',
        'https://hotelsystemback.osus.network',
        'http://localhost:4200',
        'http://127.0.0.1:4200',
        'http://localhost:4300',
        'http://127.0.0.1:4300',
        'http://localhost:4400',
        'http://127.0.0.1:4400',
    ]))),

    'allowed_origins_patterns' => [
        '#^https://([a-z0-9-]+\.)?osus\.network$#',
        '#^https://([a-z0-9-]+\.)?checkin-pms\.com$#',
        '#^http://192\.168\.\d{1,3}\.\d{1,3}(:\d+)?$#',
        '#^http://10\.\d{1,3}\.\d{1,3}\.\d{1,3}(:\d+)?$#',
    ],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 86400,

    'supports_credentials' => false,

];
