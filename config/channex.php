<?php

return [
    // "staging" (sandbox) or "production" — never point an untested integration
    // at production, it would publish real availability to real OTA channels.
    'environment' => env('CHANNEX_ENVIRONMENT', 'staging'),

    'base_url' => env('CHANNEX_BASE_URL', 'https://staging.channex.io/api/v1'),

    // Sent as the "user-api-key" request header on every call.
    'api_key' => env('CHANNEX_API_KEY'),

    // Shared secret Channex sends back in a custom header on every webhook call
    // (registered via ChannexClient::registerWebhook()) — how we verify a
    // webhook request actually came from Channex.
    'webhook_secret' => env('CHANNEX_WEBHOOK_SECRET'),

    'timeout' => (int) env('CHANNEX_TIMEOUT', 15),

    'enabled' => (bool) env('CHANNEX_ENABLED', false),

    // How many days ahead availability/rates are pushed on a full resync.
    'ari_push_horizon_days' => (int) env('CHANNEX_ARI_PUSH_HORIZON_DAYS', 180),
];
