<?php

namespace App\Http\Controllers;

use App\Models\DynamicPricingRule;
use App\Models\RoomType;
use Illuminate\Http\Request;

/**
 * CRUD for the occupancy/lead-time percentage rules a room type's dynamic
 * pricing uses (see PricingEngine::buildDynamicPricingBreakdown() and
 * DynamicPricingService). Gated by the existing "manage pricing plans"
 * permission — this lives inside the same Pricing admin screen, not a
 * separate feature.
 */
class DynamicPricingRuleController extends Controller
{
    public function index(int $roomTypeId)
    {
        try {
            $roomType = RoomType::findOrFail($roomTypeId);
            $rules = $roomType->dynamicPricingRules()->orderBy('metric')->orderBy('range_from')->get();

            return \SuccessData('Dynamic pricing rules retrieved', $rules);
        } catch (\Exception $e) {
            return \Failed($e->getMessage());
        }
    }

    public function store(Request $request, int $roomTypeId)
    {
        $request->validate([
            'metric' => 'required|in:occupancy,lead_time',
            'range_from' => 'required|integer|min:0',
            'range_to' => 'nullable|integer|gt:range_from',
            'adjustment_percent' => 'required|numeric|min:-50|max:200',
        ]);

        try {
            $roomType = RoomType::findOrFail($roomTypeId);

            $rule = $roomType->dynamicPricingRules()->create([
                'metric' => $request->metric,
                'range_from' => $request->range_from,
                'range_to' => $request->range_to,
                'adjustment_percent' => $request->adjustment_percent,
            ]);

            return \SuccessData('Dynamic pricing rule created', $rule);
        } catch (\Exception $e) {
            return \Failed($e->getMessage());
        }
    }

    public function update(Request $request, int $id)
    {
        $request->validate([
            'metric' => 'sometimes|required|in:occupancy,lead_time',
            'range_from' => 'sometimes|required|integer|min:0',
            'range_to' => 'nullable|integer|gt:range_from',
            'adjustment_percent' => 'sometimes|required|numeric|min:-50|max:200',
        ]);

        try {
            $rule = DynamicPricingRule::findOrFail($id);
            $rule->update($request->only(['metric', 'range_from', 'range_to', 'adjustment_percent']));

            return \SuccessData('Dynamic pricing rule updated', $rule);
        } catch (\Exception $e) {
            return \Failed($e->getMessage());
        }
    }

    public function destroy(int $id)
    {
        try {
            $rule = DynamicPricingRule::findOrFail($id);
            $rule->delete();

            return \SuccessData('Dynamic pricing rule deleted', null);
        } catch (\Exception $e) {
            return \Failed($e->getMessage());
        }
    }
}
