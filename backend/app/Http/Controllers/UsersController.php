<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use App\Models\User;
use App\Models\Permission;
use App\Models\Job_title;
use App\Models\Department;
use Spatie\Permission\Models\Role;
use App\Http\Resources\UserResource;
use App\Http\Requests\User\AddUserRequest;
use App\Http\Requests\User\UpdateUserRequest;
use App\Http\Requests\User\InActiveUserRequest;
use App\Http\Requests\User\LoginRequest;
use App\Http\Resources\Login\LoginResource;
use Exception;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

class UsersController extends Controller
{
    /**
     * Login user
     */
    public function login(LoginRequest $request)
    {
        if (!extension_loaded('pdo') || !extension_loaded('pdo_mysql')) {
            return \Failed(
                'PHP extensions pdo and pdo_mysql are disabled on the server. Enable them in cPanel → Select PHP Version for hotelsystemback.osus.network, then retry login.',
                503
            );
        }

        try {
            $arr = Arr::only($request->validated(), ['job_number', 'password']);
            $where = ['job_number' => $arr['job_number']];
            $user = User::where($where)->first();
            if (!$user) {
                return \Failed('Invalid job number', 401);
            }
            if (!$user->active) {
                return \Failed('This account is disActive', 403);
            }
            if (!Hash::check($arr['password'], $user->password)) {
                return \Failed('Wrong Password', 401);
            }

            // Spatie "teams" is enabled with team_foreign_key=hotel_id (multi-hotel
            // support) — role/permission lookups are scoped to whichever hotel is
            // "current". At login there's no X-Hotel-Id yet, so default to the
            // user's first accessible hotel (same fallback ResolveCurrentHotel
            // uses on every later request) so the response isn't empty.
            $availableHotels = $user->hotels()->with('tenant')->get(['hotels.id', 'hotels.name_ar', 'hotels.name_en', 'hotels.tenant_id']);

            // Reject before a token is ever issued — a deactivated tenant or
            // a trial/subscription past its end date must not let the
            // customer in at all, not just fail their next click.
            // ResolveCurrentHotel re-checks both on every later request for
            // sessions already in progress.
            $deactivatedTenant = $availableHotels->first(fn ($h) => $h->tenant && !$h->tenant->is_active);
            if ($deactivatedTenant) {
                return \Failed('This account has been deactivated.', 403);
            }
            $expiredTenant = $availableHotels->first(fn ($h) => $h->tenant && !$h->tenant->isSubscriptionActive());
            if ($expiredTenant) {
                return \Failed('This subscription has expired.', 403);
            }

            $token = $user->createToken('authToken');
            $user->setAttribute('token', $token);

            $defaultHotelId = (int) ($availableHotels->first()->id ?? 0);
            if ($defaultHotelId) {
                app(\Spatie\Permission\PermissionRegistrar::class)->setPermissionsTeamId($defaultHotelId);
            }
            $user->setAttribute('availableHotels', $availableHotels);
            $user->setAttribute('currentHotelId', $defaultHotelId);

            $user->load(['roles.permissions', 'permissions']);

            return \SuccessData('Logged In Success', new LoginResource($user));
        } catch (\Throwable $e) {
            report($e);

            $message = config('app.debug')
                ? $e->getMessage()
                : 'Login failed. Run server setup: php artisan migrate --force && php artisan passport:keys && php artisan hotel:ensure-admin';

            return \Failed($message, 500);
        }
    }

    /**
     * Public pre-flight check for production deployment (no auth).
     */
    public function setupCheck()
    {
        $checks = [];

        $checks[] = [
            'name' => 'php_pdo_extension',
            'status' => extension_loaded('pdo') && extension_loaded('pdo_mysql') ? 'pass' : 'fail',
            'message' => sprintf(
                'PHP %s (%s) | pdo=%s | pdo_mysql=%s | ini=%s',
                PHP_VERSION,
                php_sapi_name(),
                extension_loaded('pdo') ? 'yes' : 'NO',
                extension_loaded('pdo_mysql') ? 'yes' : 'NO',
                php_ini_loaded_file() ?: 'unknown'
            ),
        ];

        if (!extension_loaded('pdo') || !extension_loaded('pdo_mysql')) {
            return \SuccessData('Setup check', [
                'ready' => false,
                'checks' => $checks,
                'action_required' => 'Enable PHP extensions pdo and pdo_mysql in cPanel → Select PHP Version (PHP 8.2+) for subdomain hotelsystemback. Then open /check-php.php to verify. Contact host if toggles are missing.',
            ], 503);
        }

        $checks[] = $this->setupCheckItem('database', function () {
            \Illuminate\Support\Facades\DB::connection()->getPdo();

            return 'Connected';
        });

        $checks[] = $this->setupCheckItem('users_table', function () {
            if (!\Illuminate\Support\Facades\Schema::hasTable('users')) {
                throw new \RuntimeException('Missing users table — run php artisan migrate --force');
            }

            return User::count() . ' users';
        });

        $checks[] = $this->setupCheckItem('passport_private_key', function () {
            $path = storage_path('oauth-private.key');
            if (!is_readable($path)) {
                throw new \RuntimeException('Missing storage/oauth-private.key — run php artisan passport:keys');
            }

            return 'Found';
        });

        $checks[] = $this->setupCheckItem('passport_public_key', function () {
            $path = storage_path('oauth-public.key');
            if (!is_readable($path)) {
                throw new \RuntimeException('Missing storage/oauth-public.key — run php artisan passport:keys');
            }

            return 'Found';
        });

        $checks[] = $this->setupCheckItem('passport_personal_client', function () {
            if (!\Illuminate\Support\Facades\Schema::hasTable('oauth_clients')) {
                throw new \RuntimeException('Missing oauth tables — run php artisan migrate --force');
            }

            $hasClient = \Laravel\Passport\Client::query()
                ->where('personal_access_client', true)
                ->exists();
            if (!$hasClient) {
                throw new \RuntimeException('No Passport personal client — run php artisan hotel:ensure-admin');
            }

            return 'Configured';
        });

        $checks[] = $this->setupCheckItem('permission_tables', function () {
            if (!\Illuminate\Support\Facades\Schema::hasTable('permissions')) {
                throw new \RuntimeException('Missing permission tables — run php artisan migrate --force && db:seed');
            }

            return 'Found';
        });

        $checks[] = [
            'name' => 'app_debug',
            'status' => config('app.debug') ? 'warn' : 'pass',
            'message' => config('app.debug') ? 'APP_DEBUG=true — disable in production' : 'APP_DEBUG=false',
        ];

        $checks[] = [
            'name' => 'frontend_url',
            'status' => env('FRONTEND_URL') ? 'pass' : 'warn',
            'message' => env('FRONTEND_URL') ?: 'FRONTEND_URL not set — CORS may fail after config:cache',
        ];

        $checks[] = $this->setupCheckItem('refund_policies', function () {
            if (!\Illuminate\Support\Facades\Schema::hasTable('refund_policies')) {
                throw new \RuntimeException('Missing refund_policies table — run migrate');
            }
            $count = \App\Models\RefundPolicy::count();
            if ($count < 1) {
                throw new \RuntimeException('No refund policies — run php artisan db:seed --class=RefundPolicySeeder --force');
            }

            return $count . ' policies';
        });

        $checks[] = [
            'name' => 'mail_smtp',
            'status' => $this->isMailConfiguredForSetup() ? 'pass' : 'warn',
            'message' => $this->isMailConfiguredForSetup()
                ? 'SMTP configured'
                : 'Mail not configured — email report delivery disabled',
        ];

        $failed = collect($checks)->where('status', 'fail')->count();

        return \SuccessData('Setup check', [
            'ready' => $failed === 0,
            'checks' => $checks,
        ], $failed === 0 ? 200 : 503);
    }

    private function setupCheckItem(string $name, callable $fn): array
    {
        try {
            return [
                'name' => $name,
                'status' => 'pass',
                'message' => (string) $fn(),
            ];
        } catch (\Throwable $e) {
            return [
                'name' => $name,
                'status' => 'fail',
                'message' => $e->getMessage(),
            ];
        }
    }

    private function isMailConfiguredForSetup(): bool
    {
        return !empty(config('mail.mailers.smtp.host'))
            && !empty(config('mail.from.address'))
            && config('mail.default') !== 'log';
    }

    /**
     * Current session profile (roles + permissions) for SPA refresh after login.
     */
    public function me(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return \Failed('Unauthorized', 401);
        }

        $user->load(['roles.permissions', 'permissions']);

        return \SuccessData('User session', [
            'id' => $user->id,
            'name' => $user->name,
            'job_number' => $user->job_number,
            'email' => $user->email,
            'role' => $user->getRoleNames()->first(),
            'roles' => $user->getRoleNames()->values()->all(),
            'permissions' => $user->getAllPermissions()->pluck('name')->values()->all(),
        ]);
    }

    /**
     * Add new user
     */
    public function store(AddUserRequest $request)
    {
        DB::beginTransaction();
        try {

            $user = User::create([
                'name'          => $request->name,
                'job_number'    => $request->job_number,
                'jobtitle_id'   => $request->jobtitle_id,
                'department_id' => $request->department_id,
                'mobile'        => $request->mobile,
                'email'         => $request->email,
                'discount_id'   => $request->discount_id ?? null,
                'active'        => 1,
                'password'      => Hash::make($request->password),
            ]);

            if ($request->filled('permission_ids')) {
                $this->addUserPermission($user->id, $request->permission_ids);
            }

            if ($request->filled('role_id')) {
                $currentHotelId = (int) app(\Spatie\Permission\PermissionRegistrar::class)->getPermissionsTeamId();
                $role = Role::where('id', $request->role_id)->where('hotel_id', $currentHotelId)->first();
                if ($role) {
                    $user->syncRoles([$role->name]);
                    $user->forgetCachedPermissions();
                }
            }

            app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

            $user->load(['jobtitle', 'department', 'discount', 'permissions', 'roles']);
            DB::commit();
            return \SuccessData('User added Successfully', new UserResource($user));
        } catch (Exception $e) {
            DB::rollBack();
            return \Failed($e->getMessage());
        }
    }

    /**
     * Update user
     */
    public function update(UpdateUserRequest $request)
    {
        $user = User::find($request->id);

        if (!$user) {
            return \Failed('User not found');
        }

        DB::beginTransaction();
        try {
            $user->update($request->only([
                'jobtitle_id',
                'department_id',
                'mobile',
                'email',
                'discount_id',
                'name'
            ]));

            if ($request->has('permission_ids')) {
                $oldPermissions = $user->permissions()->pluck('permission_id')->toArray();
                $newPermissions = $request->permission_ids;
                sort($oldPermissions);
                sort($newPermissions);
                if ($oldPermissions !== $newPermissions) {
                    $this->addUserPermission($request->id, $newPermissions);
                }
            }

            $user->load(['jobtitle', 'department', 'discount', 'permissions']);
            DB::commit();
            return \Success('Record Update Successfully');
        } catch (Exception $e) {
            DB::rollBack();
            return \Failed($e->getMessage());
        }
    }

    /**
     * Deactivate user
     */
    public function inActive(InActiveUserRequest $request)
    {
        $user = User::find($request->id);

        if (!$user) {
            return \Failed('User not found');
        }

        try {
            $newStatus = !$user->active;
            $user->update(['active' => $newStatus]);
            $statusText = $newStatus ? 'activated' : 'deactivated';
            return \Success("User $statusText successfully");
        } catch (Exception $e) {
            return \Failed($e->getMessage());
        }
    }

    /**
     * Get all users info
     */
    public function index(Request $request)
    {
        try {
            $perPage = \returnPerPage();
            $tenantId = (int) $request->attributes->get('currentTenantId');
            $activeUsers = User::with(['jobtitle', 'department', 'discount', 'permissions'])
                ->whereHas('hotels', fn ($q) => $q->where('hotels.tenant_id', $tenantId))
                ->where('active', 1)
                ->paginate($perPage);
            return \Pagination($activeUsers);
        } catch (Exception $e) {
            return \Failed($e->getMessage());
        }
    }

    public function getInfoUsers(Request $request)
    {
        try {
            $perPage = $request->input('per_page', 10);
            $search = $request->input('search');
            $tenantId = (int) $request->attributes->get('currentTenantId');

            $query = User::with(['roles.permissions', 'permissions', 'jobtitle', 'department', 'discount'])
                ->whereHas('hotels', fn ($q) => $q->where('hotels.tenant_id', $tenantId));

            if ($search) {
                $query->where(function($q) use ($search) {
                    $q->where('name', 'like', "%$search%")
                      ->orWhere('email', 'like', "%$search%")
                      ->orWhere('job_number', 'like', "%$search%");
                });
            }

            $users = $query->paginate($perPage);

            return \SuccessData('Users retrieved successfully', [
                'users' => $users->items(),
                'pagination' => [
                    'total' => $users->total(),
                    'per_page' => $users->perPage(),
                    'current_page' => $users->currentPage(),
                    'last_page' => $users->lastPage(),
                    'from' => $users->firstItem(),
                    'to' => $users->lastItem()
                ]
            ]);
        } catch (Exception $e) {
            return \Failed($e->getMessage());
        }
    }

    /**
     * Add user permissions
     */
    private function addUserPermission($user_id, $permission_ids)
    {
        $user = User::findOrFail($user_id);
        $permissionNames = Permission::whereIn('id', $permission_ids)->pluck('name')->all();
        $user->syncPermissions($permissionNames);
    }

    public function changePassword(Request $request)
    {
        try {
            $request->validate([
                'old_password' => 'required',
                'new_password' => 'required|min:6|confirmed',
            ]);

            $user = auth()->user();

            if (!Hash::check($request->old_password, $user->password)) {
                return \Failed('The old password does not match our records.');
            }

            $user->update([
                'password' => Hash::make($request->new_password)
            ]);

            return \Success('Password changed successfully');
        } catch (Exception $e) {
            return \Failed($e->getMessage());
        }
    }

    public function loginError(){
        return response()->json([
            'success'=>false,
            'message'=>'You need to Sign In to access',
            'code'=>403
        ],403);
    }
}
