<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$user = App\Models\User::where('job_number', '001')->first();
if (!$user) {
    echo "User 001 not found\n";
    exit(1);
}

$user->load(['roles.permissions', 'permissions']);
echo "User: {$user->name} (id={$user->id}, active={$user->active})\n";
echo 'Roles: ' . $user->getRoleNames()->implode(', ') . "\n";
echo 'Permissions via getAllPermissions: ' . $user->getAllPermissions()->count() . "\n";
echo 'hasRole admin: ' . ($user->hasRole('admin', 'api') ? 'yes' : 'no') . "\n";
echo 'hasPermissionTo view reservations: ' . ($user->hasPermissionTo('view reservations', 'api') ? 'yes' : 'no') . "\n";
echo 'HotelLiveChannelAccess::allows: ' . (App\Support\HotelLiveChannelAccess::allows($user) ? 'yes' : 'no') . "\n";
