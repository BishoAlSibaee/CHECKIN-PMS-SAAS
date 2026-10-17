<?php

namespace App\Http\Controllers;

use App\Models\Hotel;
use App\Models\RoomType;
use App\Models\Tenant;
use App\Models\User;
use App\Services\HotelRoleProvisioningService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Spatie\Permission\PermissionRegistrar;

/**
 * Onboards a brand-new SaaS customer: a Tenant, its first Hotel (branch),
 * and that hotel's first admin user, in one transaction — replacing what
 * used to be a manual SSH + Tinker session per new customer. Additional
 * branches for an existing customer go through addHotel() instead.
 *
 * Reachable only through the "platform_admin" guard (see routes/platform_admin.php
 * and App\Models\PlatformAdmin) — no account belonging to any tenant's hotel
 * can authenticate against that guard at all, so there is no permission bit
 * to double-check here the way earlier revisions of this controller did.
 */
class TenantsController extends Controller
{
    public function __construct(private HotelRoleProvisioningService $roleProvisioningService) {}

    public function index(Request $request)
    {
        try {
            $tenants = Tenant::withCount('hotels')->with('createdBy:id,name,email')->orderBy('id')->get()
                ->map(function (Tenant $tenant) {
                    $tenant->is_subscription_active = $tenant->isSubscriptionActive();

                    return $tenant;
                });

            return \SuccessData('Tenants retrieved', $tenants);
        } catch (\Exception $e) {
            return \Failed($e->getMessage());
        }
    }

    public function show(Tenant $tenant)
    {
        try {
            $tenant->load(['hotels', 'createdBy:id,name,email']);
            $tenant->is_subscription_active = $tenant->isSubscriptionActive();

            return \SuccessData('Tenant retrieved', $tenant);
        } catch (\Exception $e) {
            return \Failed($e->getMessage());
        }
    }

    /**
     * Edits a tenant's own details and/or flips its on/off switch. Deactivating
     * locks every user of every one of its hotels out immediately — same two
     * enforcement points as subscription expiry (UsersController::login and
     * ResolveCurrentHotel), see Tenant::isAccessAllowed().
     */
    public function update(Request $request, Tenant $tenant)
    {
        $request->validate([
            'name_ar' => 'sometimes|string|max:255',
            'name_en' => 'sometimes|string|max:255',
            'is_active' => 'sometimes|boolean',
        ]);

        try {
            $tenant->update($request->only(['name_ar', 'name_en', 'is_active']));
            $tenant->is_subscription_active = $tenant->isSubscriptionActive();

            return \SuccessData('Tenant updated', $tenant);
        } catch (\Exception $e) {
            return \Failed($e->getMessage());
        }
    }

    /**
     * Extends (or clears) a tenant's subscription end date. Extending adds
     * days on top of whatever time is already left — renewing a few days
     * early doesn't forfeit the remainder — but restarts from today once a
     * subscription has already lapsed, so a lapsed customer isn't
     * retroactively credited for the time they were locked out.
     */
    public function renewSubscription(Request $request, Tenant $tenant)
    {
        $request->validate([
            'no_expiry' => 'sometimes|boolean',
            'extend_days' => 'required_if:no_expiry,false|nullable|integer|min:1',
        ]);

        try {
            if ($request->boolean('no_expiry')) {
                $tenant->update(['subscription_ends_at' => null]);
            } else {
                $base = ($tenant->subscription_ends_at && $tenant->subscription_ends_at->isFuture())
                    ? $tenant->subscription_ends_at
                    : now();
                $tenant->update(['subscription_ends_at' => $base->copy()->addDays((int) $request->extend_days)]);
            }

            $tenant->is_subscription_active = $tenant->isSubscriptionActive();

            return \SuccessData('Subscription renewed', $tenant);
        } catch (\Exception $e) {
            return \Failed($e->getMessage());
        }
    }

    private function propertySpecRules(): array
    {
        return [
            'buildings_count' => 'required|integer|min:1|max:26',
            'floors_count' => 'required|integer|min:1|max:50',
            'rooms_per_floor' => 'required|integer|min:1|max:50',
        ];
    }

    public function store(Request $request)
    {
        $request->validate([
            'tenant_name_ar' => 'required|string|max:255',
            'tenant_name_en' => 'required|string|max:255',
            'hotel_name_ar' => 'required|string|max:255',
            'hotel_name_en' => 'required|string|max:255',
            ...$this->propertySpecRules(),
            'subscription_days' => 'nullable|integer|min:1',
            'admin_name' => 'required|string|max:255',
            'admin_job_number' => ['required', 'string', Rule::unique('users', 'job_number')],
            'admin_email' => ['required', 'email', Rule::unique('users', 'email')],
            'admin_mobile' => ['required', 'string', Rule::unique('users', 'mobile')],
            'admin_password' => 'required|string|min:8',
        ]);

        try {
            $result = DB::transaction(function () use ($request) {
                $tenant = Tenant::create([
                    'name_ar' => $request->tenant_name_ar,
                    'name_en' => $request->tenant_name_en,
                    'is_active' => true,
                    'subscription_ends_at' => $request->filled('subscription_days')
                        ? now()->addDays((int) $request->subscription_days)
                        : null,
                    'created_by_platform_admin_id' => $request->user()->id,
                ]);

                $hotel = Hotel::create([
                    'tenant_id' => $tenant->id,
                    'name_ar' => $request->hotel_name_ar,
                    'name_en' => $request->hotel_name_en,
                    'is_active' => true,
                ]);

                $this->roleProvisioningService->provisionDefaultRoles((int) $hotel->id);
                $this->seedProperty(
                    (int) $hotel->id,
                    (int) $request->buildings_count,
                    (int) $request->floors_count,
                    (int) $request->rooms_per_floor,
                );

                // No jobtitle/department exist yet for a brand-new tenant —
                // same reason AdminSeeder creates its bootstrap admin this
                // way instead of going through AddUserRequest.
                $admin = User::create([
                    'name' => $request->admin_name,
                    'job_number' => $request->admin_job_number,
                    'email' => $request->admin_email,
                    'mobile' => $request->admin_mobile,
                    'jobtitle_id' => null,
                    'department_id' => null,
                    'discount_id' => null,
                    'active' => 1,
                    'password' => Hash::make($request->admin_password),
                ]);

                $admin->hotels()->attach($hotel->id);

                $registrar = app(PermissionRegistrar::class);
                $previousTeamId = $registrar->getPermissionsTeamId();
                $registrar->setPermissionsTeamId($hotel->id);
                $admin->syncRoles(['admin']);
                $registrar->setPermissionsTeamId($previousTeamId);
                $registrar->forgetCachedPermissions();

                return ['tenant' => $tenant, 'hotel' => $hotel, 'admin' => $admin];
            });

            return \SuccessData('Tenant onboarded', [
                'tenant' => $result['tenant'],
                'hotel' => $result['hotel'],
                'admin' => [
                    'job_number' => $result['admin']->job_number,
                    'name' => $result['admin']->name,
                ],
            ]);
        } catch (\Exception $e) {
            return \Failed($e->getMessage());
        }
    }

    /**
     * Adds another branch (Hotel) to an EXISTING tenant — a customer that
     * outgrows a single property doesn't need to be re-onboarded. Reuses the
     * same property-generation logic as store(); the tenant's own admin gets
     * access to the new branch automatically (every admin role is granted
     * to every hotel the tenant creates, so no re-assignment step here).
     */
    public function addHotel(Request $request, Tenant $tenant)
    {
        $request->validate([
            'hotel_name_ar' => 'required|string|max:255',
            'hotel_name_en' => 'required|string|max:255',
            ...$this->propertySpecRules(),
        ]);

        try {
            $hotel = DB::transaction(function () use ($request, $tenant) {
                $hotel = Hotel::create([
                    'tenant_id' => $tenant->id,
                    'name_ar' => $request->hotel_name_ar,
                    'name_en' => $request->hotel_name_en,
                    'is_active' => true,
                ]);

                $this->roleProvisioningService->provisionDefaultRoles((int) $hotel->id);
                $this->seedProperty(
                    (int) $hotel->id,
                    (int) $request->buildings_count,
                    (int) $request->floors_count,
                    (int) $request->rooms_per_floor,
                );

                // Every existing admin of this tenant's other hotels should
                // be able to operate the new branch immediately too. Queried
                // directly against model_has_roles (same withoutGlobalScopes()
                // reasoning as HotelRoleProvisioningService::role()) rather
                // than through the teams-scoped role() relation scope, whose
                // interaction with a BelongsToMany proxy isn't exercised
                // anywhere else in this codebase.
                $registrar = app(PermissionRegistrar::class);
                $previousTeamId = $registrar->getPermissionsTeamId();
                $otherHotelIds = $tenant->hotels()->where('id', '!=', $hotel->id)->pluck('id');

                foreach ($otherHotelIds as $otherHotelId) {
                    $adminRoleId = \Spatie\Permission\Models\Role::withoutGlobalScopes()
                        ->where('hotel_id', $otherHotelId)
                        ->where('name', 'admin')
                        ->where('guard_name', 'api')
                        ->value('id');

                    if (!$adminRoleId) {
                        continue;
                    }

                    $existingAdminIds = DB::table('model_has_roles')
                        ->where('role_id', $adminRoleId)
                        ->where('hotel_id', $otherHotelId)
                        ->where('model_type', User::class)
                        ->pluck('model_id');

                    foreach (User::whereIn('id', $existingAdminIds)->get() as $existingAdmin) {
                        $existingAdmin->hotels()->syncWithoutDetaching([$hotel->id]);
                        $registrar->setPermissionsTeamId($hotel->id);
                        $existingAdmin->syncRoles(['admin']);
                    }
                }
                $registrar->setPermissionsTeamId($previousTeamId);
                $registrar->forgetCachedPermissions();

                return $hotel;
            });

            return \SuccessData('Branch added', $hotel);
        } catch (\Exception $e) {
            return \Failed($e->getMessage());
        }
    }

    /**
     * Gives a brand-new hotel a ready-to-use property instead of an empty
     * shell: $buildingsCount buildings, $floorsCount floors each,
     * $roomsPerFloor rooms per floor, numbered the same way ClientTrialSeeder
     * does within each building (floor*100+i, e.g. 101-110, 201-210...), all
     * under one default room type. Pricing plans and room-type variety are
     * left to the normal pricing screen afterward.
     */
    private function seedProperty(int $hotelId, int $buildingsCount, int $floorsCount, int $roomsPerFloor): void
    {
        $roomTypeId = RoomType::updateOrCreate(
            ['name_en' => 'Standard Room'],
            [
                'name_ar' => 'غرفة قياسية',
                'description' => 'Standard Room — غرفة قياسية',
                'Min_daily_price' => 150,
                'Max_daily_price' => 300,
                'Min_monthly_price' => 3500,
                'Max_monthly_price' => 6500,
                'active_type' => 1,
            ]
        )->id;

        for ($b = 1; $b <= $buildingsCount; $b++) {
            $buildingLabel = chr(64 + $b); // 1 -> A, 2 -> B, ...

            $buildingId = DB::table('buildings')->insertGetId([
                'hotel_id' => $hotelId,
                'name' => 'Building '.$buildingLabel,
                'number' => $buildingLabel,
                'active' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            for ($floor = 1; $floor <= $floorsCount; $floor++) {
                $floorId = DB::table('floors')->insertGetId([
                    'building_id' => $buildingId,
                    'number' => (string) $floor,
                    'active' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                for ($i = 1; $i <= $roomsPerFloor; $i++) {
                    DB::table('rooms')->insert([
                        'building_id' => $buildingId,
                        'floor_id' => $floorId,
                        'suite_id' => null,
                        'number' => (string) ($floor * 100 + $i),
                        'room_type_id' => $roomTypeId,
                        'capacity' => 2,
                        'roomStatus' => 1,
                        'active' => 1,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
        }
    }
}
