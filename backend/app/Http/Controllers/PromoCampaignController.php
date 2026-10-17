<?php

namespace App\Http\Controllers;

use App\Models\Guest_classification;
use App\Models\PromoCampaign;
use Illuminate\Http\Request;
use App\Services\PromoAudienceService;
use Illuminate\Support\Facades\DB;

/**
 * Drafts a promotional message campaign (audience + message text) for
 * later sending. There is deliberately NO send action here — see the plan
 * doc and TaqnyatService's docblock. Do not add one without explicit
 * sign-off; PromoCampaignTest asserts no call ever reaches Taqnyat's
 * message-sending endpoint from this controller.
 */
class PromoCampaignController extends Controller
{
    public function __construct(private PromoAudienceService $audienceService) {}

    public function guestClassifications()
    {
        try {
            $classifications = Guest_classification::where('active', 1)->get(['id', 'name_ar', 'name_en']);

            return \SuccessData('Guest classifications retrieved', $classifications);
        } catch (\Exception $e) {
            return \Failed($e->getMessage());
        }
    }

    public function audiencePreview(Request $request)
    {
        $request->validate([
            'audience_type' => 'required|in:manual,classification',
            'guest_classification_id' => 'required_if:audience_type,classification|nullable|numeric',
            'client_ids' => 'required_if:audience_type,manual|nullable|array',
            'client_ids.*' => 'numeric',
        ]);

        try {
            $tenantId = (int) $request->attributes->get('currentTenantId');
            $clients = $request->audience_type === 'classification'
                ? $this->audienceService->classificationAudience((int) $request->guest_classification_id, $tenantId)
                : $this->audienceService->manualAudience(array_map('intval', $request->client_ids ?? []), $tenantId);

            $rows = $clients->map(fn ($c) => [
                'id' => $c->id,
                'name' => trim($c->first_name.' '.$c->last_name),
                'phone' => $this->audienceService->formatInternationalNumber($c->international_code, $c->mobile),
            ])->values();

            return \SuccessData('Audience preview', ['count' => $rows->count(), 'clients' => $rows]);
        } catch (\Exception $e) {
            return \Failed($e->getMessage());
        }
    }

    public function index(Request $request)
    {
        try {
            $hotelId = $request->attributes->get('currentHotelId');
            $perPage = \returnPerPage();

            $campaigns = PromoCampaign::query()
                ->when($hotelId, fn ($q) => $q->where('hotel_id', $hotelId))
                ->with('creator')
                ->orderByDesc('id')
                ->paginate($perPage);

            return \Pagination($campaigns);
        } catch (\Exception $e) {
            return \Failed($e->getMessage());
        }
    }

    public function store(Request $request)
    {
        $request->validate([
            'message_body' => 'required|string|max:1000',
            'audience_type' => 'required|in:manual,classification',
            'guest_classification_id' => 'required_if:audience_type,classification|nullable|numeric',
            'client_ids' => 'required_if:audience_type,manual|nullable|array',
            'client_ids.*' => 'numeric',
        ]);

        try {
            $hotelId = (int) $request->attributes->get('currentHotelId');
            $tenantId = (int) $request->attributes->get('currentTenantId');

            $clients = $request->audience_type === 'classification'
                ? $this->audienceService->classificationAudience((int) $request->guest_classification_id, $tenantId)
                : $this->audienceService->manualAudience(array_map('intval', $request->client_ids ?? []), $tenantId);

            if ($clients->isEmpty()) {
                return \Failed('No matching clients found for this audience.', 422);
            }

            $campaign = DB::transaction(function () use ($request, $hotelId, $clients) {
                $campaign = PromoCampaign::create([
                    'hotel_id' => $hotelId,
                    'channel' => 'sms',
                    'message_body' => $request->message_body,
                    'audience_type' => $request->audience_type,
                    'guest_classification_id' => $request->audience_type === 'classification' ? $request->guest_classification_id : null,
                    'recipient_count' => $clients->count(),
                    'status' => PromoCampaign::STATUS_DRAFT,
                    'created_by' => auth()->id(),
                ]);

                foreach ($clients as $client) {
                    $campaign->recipients()->create([
                        'client_id' => $client->id,
                        'phone_snapshot' => $this->audienceService->formatInternationalNumber($client->international_code, $client->mobile),
                    ]);
                }

                return $campaign;
            });

            return \SuccessData('Campaign saved as draft', $campaign->load('recipients'));
        } catch (\Exception $e) {
            return \Failed($e->getMessage());
        }
    }
}
