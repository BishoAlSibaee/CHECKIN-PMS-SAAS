<?php

return [
    'admin_password_override' => env('HOTEL_ADMIN_PASSWORD'),
    'platform_admin_email_override' => env('PLATFORM_ADMIN_EMAIL'),
    'platform_admin_password_override' => env('PLATFORM_ADMIN_PASSWORD'),

    // Where the standalone PlatformAdmin Angular app lives — password-reset
    // emails link here, never to a Laravel web route (this backend serves
    // no such page for that app).
    'platform_admin_frontend_url' => env('PLATFORM_ADMIN_FRONTEND_URL', 'http://localhost:4400'),
];
