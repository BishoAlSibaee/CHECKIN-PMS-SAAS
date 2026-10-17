<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\User;
use App\Services\PropertyPermissionBundle;
use Spatie\Permission\Models\Role;

$role = Role::where('name', 'Property Manager')->first();
if (!$role) {
    echo "Property Manager role not found\n";
    exit(0);
}

$current = $role->permissions->pluck('name')->all();
$expanded = PropertyPermissionBundle::expand($current);
$role->syncPermissions($expanded);
app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

foreach (User::role($role->name)->get() as $user) {
    $user->forgetCachedPermissions();
}

echo "Updated role: {$role->name}\n";
echo 'Permissions: ' . json_encode($role->fresh()->permissions->pluck('name')->all(), JSON_UNESCAPED_UNICODE) . "\n";
