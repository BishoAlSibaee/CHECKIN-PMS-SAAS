<?php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Models\Permission;

class CheckPermission
{
    public function handle(Request $request, Closure $next, $permission)
    {
        if (!Auth::check()) {
            return redirect('login');
        }

        $user = Auth::user();

        // Check if user is active
        if ($user->active != 1) {
            abort(403, 'Your account is inactive.');
        }

        // Get the permission from database to check if it's active
        $permissionData = Permission::where('name_en', $permission)->first();

        // If permission doesn't exist or is inactive, allow access
        if (!$permissionData || $permissionData->active != 1) {
            return $next($request);
        }

        // Check if user has the permission
        $userPermissions = $user->permissions()
            ->where('active', 1)
            ->pluck('name_en')
            ->toArray();

        if (!in_array($permission, $userPermissions)) {
            abort(403, 'You do not have permission.');
        }

        return $next($request);
    }
}
