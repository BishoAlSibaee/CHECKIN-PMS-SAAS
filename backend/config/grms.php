<?php

return [
    // Guest Room Management System — controls in-room AC/lighting/door-lock state
    // based on reservation/occupancy changes.
    'base_url' => env('GRMS_BASE_URL', 'https://samples.checkin-grms.com/api'),
    // Sent as a plain "api_token" field in the request body, per GRMS's contract —
    // not a Bearer/Authorization header.
    'api_token' => env('GRMS_API_TOKEN'),
    'timeout' => (int) env('GRMS_TIMEOUT', 10),
    'enabled' => (bool) env('GRMS_ENABLED', false),
    // Inbound direction — GRMS calls OUR webhook (room-ready after
    // housekeeping) and authenticates with this shared secret in a header,
    // same pattern as config/channex.php's webhook_secret.
    'webhook_secret' => env('GRMS_WEBHOOK_SECRET'),
];
