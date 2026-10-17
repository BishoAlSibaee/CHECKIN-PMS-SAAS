<?php

namespace App\Http\Controllers;

use App\Models\Hotel;
use App\Models\HotelTaxProfile;
use App\Models\User;
use App\Services\HotelRoleProvisioningService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class HotelsController extends Controller
{
    public function __construct(private HotelRoleProvisioningService $roleProvisioningService) {}

    private function currentTenantId(Request $request): int
    {
        return (int) $request->attributes->get('currentTenantId');
    }

    public function index(Request $request)
    {
        try {
            $hotels = Hotel::where('tenant_id', $this->currentTenantId($request))
                ->withCount(['buildings', 'users'])
                ->with(['taxProfile', 'sharesTaxWith:id,name_ar,name_en'])
                ->orderBy('id')
                ->get();

            return \SuccessData('Hotels retrieved', $hotels);
        } catch (\Exception $e) {
            return \Failed($e->getMessage());
        }
    }

    /**
     * @return array{shares_tax_with_hotel_id: int|null}
     */
    private function validateTaxInput(Request $request, ?int $currentHotelId = null): array
    {
        $tenantId = $this->currentTenantId($request);

        $request->validate([
            'tax_mode' => 'required|in:separate,shared',
            'shares_tax_with_hotel_id' => [
                Rule::requiredIf(fn () => $request->tax_mode === 'shared'),
                'nullable', 'integer',
                Rule::exists('hotels', 'id')->where('tenant_id', $tenantId),
            ],
            'vat_number' => [Rule::requiredIf(fn () => $request->tax_mode === 'separate'), 'nullable', 'string', 'max:20'],
            'org_name_ar' => [Rule::requiredIf(fn () => $request->tax_mode === 'separate'), 'nullable', 'string', 'max:255'],
            'org_name_en' => [Rule::requiredIf(fn () => $request->tax_mode === 'separate'), 'nullable', 'string', 'max:255'],
            'cr_number' => 'nullable|string|max:20',
            'org_unit_name' => 'nullable|string|max:255',
            'location' => 'nullable|string|max:255',
            'industry' => 'nullable|string|max:255',
        ]);

        if ($request->tax_mode === 'shared') {
            $targetId = (int) $request->shares_tax_with_hotel_id;
            if ($currentHotelId !== null && $targetId === $currentHotelId) {
                abort(422, 'A hotel cannot share its tax registration with itself.');
            }

            $target = Hotel::find($targetId);
            if ($target !== null && $target->shares_tax_with_hotel_id !== null) {
                abort(422, 'The selected hotel does not hold its own tax registration — pick the hotel it shares with instead.');
            }
        }

        return [
            'shares_tax_with_hotel_id' => $request->tax_mode === 'shared' ? (int) $request->shares_tax_with_hotel_id : null,
        ];
    }

    /** @param  array<string, mixed>  $input */
    private function syncTaxProfile(Hotel $hotel, Request $request, array $input): void
    {
        $hotel->update(['shares_tax_with_hotel_id' => $input['shares_tax_with_hotel_id']]);

        if ($request->tax_mode === 'separate') {
            HotelTaxProfile::updateOrCreate(
                ['hotel_id' => $hotel->id],
                [
                    'vat_number' => $request->vat_number,
                    'cr_number' => $request->cr_number,
                    'org_name_ar' => $request->org_name_ar,
                    'org_name_en' => $request->org_name_en,
                    'org_unit_name' => $request->org_unit_name,
                    'location' => $request->location,
                    'industry' => $request->industry,
                ]
            );
        } else {
            // Now borrowing another hotel's registration — its own profile (if
            // any, from before it switched) no longer applies.
            HotelTaxProfile::where('hotel_id', $hotel->id)->delete();
        }
    }

    public function store(Request $request)
    {
        $request->validate([
            'name_ar' => 'required|string|max:255',
            'name_en' => 'required|string|max:255',
        ]);

        $taxInput = $this->validateTaxInput($request);

        try {
            $hotel = Hotel::create([
                'tenant_id' => $this->currentTenantId($request),
                'name_ar' => $request->name_ar,
                'name_en' => $request->name_en,
                'is_active' => true,
            ]);

            $this->syncTaxProfile($hotel, $request, $taxInput);

            $this->roleProvisioningService->provisionDefaultRoles((int) $hotel->id);

            // Whoever created the hotel gets access to it immediately — otherwise
            // it's an orphaned hotel nobody (not even its creator) can open.
            $hotel->users()->attach(auth()->id());

            return \SuccessData('Hotel created', $hotel->load('taxProfile'));
        } catch (\Exception $e) {
            return \Failed($e->getMessage());
        }
    }

    public function update(Request $request, int $id)
    {
        $request->validate([
            'name_ar' => 'sometimes|required|string|max:255',
            'name_en' => 'sometimes|required|string|max:255',
            'is_active' => 'sometimes|boolean',
        ]);

        try {
            $hotel = Hotel::where('tenant_id', $this->currentTenantId($request))->find($id);
            if (!$hotel) {
                return \Failed('Hotel not found', 404);
            }

            $hotel->update($request->only(['name_ar', 'name_en', 'is_active']));

            if ($request->has('tax_mode')) {
                $taxInput = $this->validateTaxInput($request, $hotel->id);
                $this->syncTaxProfile($hotel, $request, $taxInput);
            }

            return \SuccessData('Hotel updated', $hotel->fresh(['taxProfile', 'sharesTaxWith']));
        } catch (\Exception $e) {
            return \Failed($e->getMessage());
        }
    }

    public function users(Request $request, int $id)
    {
        try {
            $tenantId = $this->currentTenantId($request);
            $hotel = Hotel::where('tenant_id', $tenantId)->find($id);
            if (!$hotel) {
                return \Failed('Hotel not found', 404);
            }

            $assignedIds = $hotel->users()->pluck('users.id');
            // A brand-new user (no hotel yet) must still show up here — this
            // may be their first assignment. Only exclude users who already
            // belong to a DIFFERENT tenant's hotel; they must never be
            // visible or grantable here.
            $allUsers = User::select('users.id', 'users.name', 'users.job_number')
                ->whereDoesntHave('hotels', fn ($q) => $q->where('hotels.tenant_id', '!=', $tenantId))
                ->orderBy('name')
                ->get();

            return \SuccessData('Hotel users retrieved', [
                'assigned' => $hotel->users()->select('users.id', 'users.name', 'users.job_number')->get(),
                'available' => $allUsers->whereNotIn('id', $assignedIds)->values(),
            ]);
        } catch (\Exception $e) {
            return \Failed($e->getMessage());
        }
    }

    public function assignUser(Request $request, int $id)
    {
        $request->validate(['user_id' => 'required|integer|exists:users,id']);

        try {
            $tenantId = $this->currentTenantId($request);
            $hotel = Hotel::where('tenant_id', $tenantId)->find($id);
            if (!$hotel) {
                return \Failed('Hotel not found', 404);
            }

            $targetUser = User::find((int) $request->user_id);
            if (!$targetUser) {
                return \Failed('User not found', 404);
            }

            // A brand-new user (no hotel yet) is fine — this may be their
            // first assignment. Only block a user who already belongs to a
            // DIFFERENT tenant's hotel; they must never be grantable here.
            $belongsToOtherTenant = $targetUser->hotels()->where('hotels.tenant_id', '!=', $tenantId)->exists();
            if ($belongsToOtherTenant) {
                return \Failed('This user already belongs to a different tenant.', 422);
            }

            $hotel->users()->syncWithoutDetaching([$targetUser->id]);

            return \SuccessData('User granted access to hotel', null);
        } catch (\Exception $e) {
            return \Failed($e->getMessage());
        }
    }

    public function removeUser(Request $request, int $id, int $userId)
    {
        try {
            $hotel = Hotel::where('tenant_id', $this->currentTenantId($request))->find($id);
            if (!$hotel) {
                return \Failed('Hotel not found', 404);
            }

            if ($hotel->users()->count() <= 1 && $hotel->users()->where('users.id', $userId)->exists()) {
                return \Failed('Cannot remove the last user with access to this hotel.', 422);
            }

            $hotel->users()->detach($userId);

            return \SuccessData('User access removed', null);
        } catch (\Exception $e) {
            return \Failed($e->getMessage());
        }
    }
}
