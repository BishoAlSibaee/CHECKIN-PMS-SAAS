<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$user = App\Models\User::whereIn('job_number', ['002', '2'])->first();
if (!$user) {
    echo "User 002 not found\n";
    exit(1);
}

echo "User #{$user->id} {$user->name} (job {$user->job_number})\n";
echo 'Roles: ' . json_encode($user->getRoleNames()->all(), JSON_UNESCAPED_UNICODE) . "\n";
echo 'Permissions: ' . json_encode($user->getAllPermissions()->pluck('name')->all(), JSON_UNESCAPED_UNICODE) . "\n";

foreach ($user->roles as $role) {
    echo "\nRole: {$role->name} (guard {$role->guard_name})\n";
    echo '  Role perms: ' . json_encode($role->permissions->pluck('name')->all(), JSON_UNESCAPED_UNICODE) . "\n";
}
