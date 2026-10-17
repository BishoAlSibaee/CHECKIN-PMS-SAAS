<?php

namespace App\Http\Controllers\Channex;

use App\Http\Controllers\Controller;
use App\Models\OtaRatePlan;
use Illuminate\Http\Request;

class OtaRatePlanController extends Controller
{
    private function currentHotelId(Request $request): int
    {
        return (int) $request->attributes->get('currentHotelId');
    }

    public function index(Request $request)
    {
        try {
            $plans = OtaRatePlan::where('hotel_id', $this->currentHotelId($request))
                ->with(['roomType:id,name_ar,name_en', 'channexMapping'])
                ->orderBy('room_type_id')
                ->get();

            return \SuccessData('OTA rate plans retrieved', $plans);
        } catch (\Exception $e) {
            return \Failed($e->getMessage());
        }
    }

    public function store(Request $request)
    {
        $request->validate([
            'room_type_id' => 'required|integer|exists:room_types,id',
            'name_ar' => 'required|string|max:255',
            'name_en' => 'required|string|max:255',
            'price_adjustment_percent' => 'required|numeric|min:-100|max:100',
            'is_refundable' => 'required|boolean',
        ]);

        try {
            $plan = OtaRatePlan::create([
                'hotel_id' => $this->currentHotelId($request),
                'room_type_id' => $request->room_type_id,
                'name_ar' => $request->name_ar,
                'name_en' => $request->name_en,
                'price_adjustment_percent' => $request->price_adjustment_percent,
                'is_refundable' => (bool) $request->is_refundable,
                'is_active' => true,
            ]);

            return \SuccessData('OTA rate plan created', $plan);
        } catch (\Exception $e) {
            return \Failed($e->getMessage());
        }
    }

    public function update(Request $request, int $id)
    {
        $request->validate([
            'name_ar' => 'sometimes|required|string|max:255',
            'name_en' => 'sometimes|required|string|max:255',
            'price_adjustment_percent' => 'sometimes|required|numeric|min:-100|max:100',
            'is_refundable' => 'sometimes|required|boolean',
            'is_active' => 'sometimes|required|boolean',
        ]);

        try {
            $plan = OtaRatePlan::where('hotel_id', $this->currentHotelId($request))->find($id);
            if (! $plan) {
                return \Failed('Rate plan not found', 404);
            }

            $plan->update($request->only(['name_ar', 'name_en', 'price_adjustment_percent', 'is_refundable', 'is_active']));

            return \SuccessData('OTA rate plan updated', $plan);
        } catch (\Exception $e) {
            return \Failed($e->getMessage());
        }
    }

    public function destroy(Request $request, int $id)
    {
        try {
            $plan = OtaRatePlan::where('hotel_id', $this->currentHotelId($request))->find($id);
            if (! $plan) {
                return \Failed('Rate plan not found', 404);
            }

            $plan->delete();

            return \Success('OTA rate plan deleted');
        } catch (\Exception $e) {
            return \Failed($e->getMessage());
        }
    }
}
