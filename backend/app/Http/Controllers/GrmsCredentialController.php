<?php

namespace App\Http\Controllers;

use App\Models\GrmsHotelCredential;
use App\Models\Hotel;
use App\Services\Grms\GrmsCredentialService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Platform-admin-only management of the per-hotel GRMS v1 HMAC credentials
 * (see GrmsCredentialService). Reachable only through the "platform_admin"
 * guard, same as TenantsController.
 */
class GrmsCredentialController extends Controller
{
    public function __construct(private GrmsCredentialService $credentialService) {}

    /** Never returns a secret — only metadata (key_id, direction, rotation history). */
    public function index(Hotel $hotel)
    {
        try {
            $credentials = GrmsHotelCredential::where('hotel_id', $hotel->id)
                ->orderByDesc('created_at')
                ->get(['id', 'hotel_id', 'direction', 'key_id', 'is_active', 'rotated_at', 'created_at']);

            return \SuccessData('Credentials retrieved', $credentials);
        } catch (\Exception $e) {
            return \Failed($e->getMessage());
        }
    }

    /**
     * Generates a brand-new credential (deactivating any existing active one
     * for the same hotel+direction) — the raw secret is returned HERE ONLY,
     * same "shown once" discipline as CreateKioskDevice's token.
     */
    public function generate(Request $request, Hotel $hotel)
    {
        $request->validate([
            'direction' => ['required', Rule::in([
                GrmsHotelCredential::DIRECTION_OUTBOUND,
                GrmsHotelCredential::DIRECTION_INBOUND,
            ])],
        ]);

        try {
            $result = $this->credentialService->generate((int) $hotel->id, $request->direction);

            return \SuccessData('Credential generated — save the secret now, it will not be shown again', [
                'key_id' => $result['credential']->key_id,
                'secret' => $result['secret'],
                'direction' => $result['credential']->direction,
            ]);
        } catch (\Exception $e) {
            return \Failed($e->getMessage());
        }
    }

    public function rotate(GrmsHotelCredential $credential)
    {
        try {
            $result = $this->credentialService->rotate((int) $credential->hotel_id, $credential->direction);

            return \SuccessData('Credential rotated — save the secret now, it will not be shown again', [
                'key_id' => $result['credential']->key_id,
                'secret' => $result['secret'],
                'direction' => $result['credential']->direction,
            ]);
        } catch (\Exception $e) {
            return \Failed($e->getMessage());
        }
    }

    /**
     * Switches a hotel between the legacy direct-webhook GRMS integration and
     * the v1 contract (see GrmsProtocolGuard, which every v1 endpoint checks
     * against this column). A hotel should normally only flip to 'v1' once
     * it has an active inbound+outbound credential pair — left to the admin's
     * judgement rather than enforced here, same as the plan's "moved gradually,
     * hotel by hotel" rollout intent.
     */
    public function updateProtocol(Request $request, Hotel $hotel)
    {
        $request->validate([
            'pms_protocol' => ['required', Rule::in(['legacy', 'v1'])],
        ]);

        try {
            $hotel->update(['pms_protocol' => $request->pms_protocol]);

            return \SuccessData('Protocol updated', [
                'id' => $hotel->id,
                'pms_protocol' => $hotel->pms_protocol,
            ]);
        } catch (\Exception $e) {
            return \Failed($e->getMessage());
        }
    }
}
