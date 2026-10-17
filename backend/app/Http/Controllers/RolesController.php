<?php

namespace App\Http\Controllers;

use App\Services\Cache\CacheInvalidator;
use App\Services\Cache\ReferenceDataCache;
use App\Services\PropertyPermissionBundle;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use App\Models\User;

class RolesController extends Controller
{
    /**
     * Spatie's "teams" feature scopes role ASSIGNMENT (model_has_roles) and
     * role name uniqueness by hotel_id, but does NOT automatically filter
     * plain Role::all()/Role::find() queries — every read/write here must
     * filter or verify hotel_id explicitly, or a caller from one hotel could
     * see or mutate another hotel's roles.
     */
    private function currentHotelId(): int
    {
        return (int) app(PermissionRegistrar::class)->getPermissionsTeamId();
    }

    /** @throws \Illuminate\Http\Exceptions\HttpResponseException via findOrFail-style 404 */
    private function findOwnedRole(int $id): Role
    {
        $role = Role::where('id', $id)->where('hotel_id', $this->currentHotelId())->first();
        if (!$role) {
            abort(404, 'Role not found');
        }

        return $role;
    }

    public function index()
    {
        try {
            $hotelId = $this->currentHotelId();
            $roles = ReferenceDataCache::remember(
                'roles',
                "with_permissions_hotel_{$hotelId}",
                fn () => Role::with('permissions')->where('hotel_id', $hotelId)->get()
            );

            return SuccessData('Roles retrieved successfully', $roles);
        } catch (\Exception $e) {
            return Failed($e->getMessage());
        }
    }

    public function store(Request $request)
    {
        try {
            $hotelId = $this->currentHotelId();

            $request->validate([
                'name' => [
                    'required',
                    Rule::unique('roles', 'name')->where(fn ($q) => $q->where('hotel_id', $hotelId)->where('guard_name', 'api')),
                ],
                'name_ar' => 'nullable',
                'permissions' => 'required|array'
            ]);

            $role = Role::create([
                'name' => $request->name,
                'name_ar' => $request->name_ar ?? $request->name,
                'description' => $request->description,
                'guard_name' => 'api'
            ]);

            $permissions = PropertyPermissionBundle::expand($request->permissions);

            $role->syncPermissions($permissions);
            app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
            CacheInvalidator::rbacChanged();

            return SuccessData('Role created successfully', $role->load('permissions'));
        } catch (\Exception $e) {
            return Failed($e->getMessage());
        }
    }

    public function update(Request $request)
    {
        try {
            $hotelId = $this->currentHotelId();

            $request->validate([
                'id' => 'required|exists:roles,id',
                'name' => [
                    'required',
                    Rule::unique('roles', 'name')->where(fn ($q) => $q->where('hotel_id', $hotelId)->where('guard_name', 'api'))->ignore($request->id),
                ],
                'name_ar' => 'nullable',
                'permissions' => 'required|array'
            ]);

            $role = $this->findOwnedRole((int) $request->id);
            $role->update([
                'name' => $request->name,
                'name_ar' => $request->name_ar ?? $request->name,
                'description' => $request->description,
            ]);

            $permissions = PropertyPermissionBundle::expand($request->permissions);

            $role->syncPermissions($permissions);
            app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
            CacheInvalidator::rbacChanged();

            return SuccessData('Role updated successfully', $role->load('permissions'));
        } catch (\Exception $e) {
            return Failed($e->getMessage());
        }
    }

    public function destroy(Request $request)
    {
        try {
            $role = $this->findOwnedRole((int) $request->id);

            if ($role->name === 'admin') {
                return Failed('Cannot delete admin role');
            }

            $role->delete();
            app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
            CacheInvalidator::rbacChanged();

            return Success('Role deleted successfully');
        } catch (\Exception $e) {
            return Failed($e->getMessage());
        }
    }

    public function assignRole(Request $request)
    {
        try {
            $request->validate([
                'userId' => 'required|exists:users,id',
                'roleId' => 'required|exists:roles,id'
            ]);

            $user = User::findOrFail($request->userId);
            $role = $this->findOwnedRole((int) $request->roleId);

            $user->syncRoles([$role->name]);
            $user->forgetCachedPermissions();
            app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

            return Success('Role assigned successfully');
        } catch (\Exception $e) {
            return Failed($e->getMessage());
        }
    }

    public function bulkAssignRole(Request $request)
    {
        try {
            $request->validate([
                'userIds' => 'required|array',
                'userIds.*' => 'exists:users,id',
                'roleId' => 'required|exists:roles,id'
            ]);

            $role = $this->findOwnedRole((int) $request->roleId);
            $users = User::whereIn('id', $request->userIds)->get();

            foreach ($users as $user) {
                $user->syncRoles([$role->name]);
                $user->forgetCachedPermissions();
            }

            app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

            return Success('Roles assigned successfully to ' . $users->count() . ' users');
        } catch (\Exception $e) {
            return Failed($e->getMessage());
        }
    }
}
