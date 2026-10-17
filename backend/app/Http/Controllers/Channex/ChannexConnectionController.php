<?php

namespace App\Http\Controllers\Channex;

use App\Http\Controllers\Controller;
use App\Models\ChannexProperty;
use App\Models\ChannexRoomTypeMapping;
use App\Services\Channex\ChannexContentSyncService;
use Illuminate\Http\Request;

class ChannexConnectionController extends Controller
{
    public function __construct(private ChannexContentSyncService $syncService) {}

    private function currentHotelId(Request $request): int
    {
        return (int) $request->attributes->get('currentHotelId');
    }

    public function status(Request $request)
    {
        try {
            $hotelId = $this->currentHotelId($request);
            $property = ChannexProperty::where('hotel_id', $hotelId)->first();
            $roomTypeMappings = ChannexRoomTypeMapping::where('hotel_id', $hotelId)
                ->with('roomType:id,name_ar,name_en')
                ->get();

            return \SuccessData('Channex connection status', [
                'enabled' => (bool) config('channex.enabled'),
                'environment' => config('channex.environment'),
                'property' => $property,
                'room_type_mappings' => $roomTypeMappings,
            ]);
        } catch (\Exception $e) {
            return \Failed($e->getMessage());
        }
    }

    /** Fire-and-forget-ish: runs inline but each step is idempotent/cheap; a real prod deploy could queue this instead. */
    public function syncNow(Request $request)
    {
        try {
            $hotelId = $this->currentHotelId($request);
            $this->syncService->syncHotel($hotelId);

            $roomTypeIds = \Illuminate\Support\Facades\DB::table('rooms')
                ->join('buildings', 'buildings.id', '=', 'rooms.building_id')
                ->where('buildings.hotel_id', $hotelId)
                ->where('rooms.active', 1)
                ->distinct()
                ->pluck('rooms.room_type_id');

            foreach ($roomTypeIds as $roomTypeId) {
                $this->syncService->syncRoomType($hotelId, (int) $roomTypeId);
            }

            $ratePlans = \App\Models\OtaRatePlan::where('hotel_id', $hotelId)->where('is_active', true)->get();
            foreach ($ratePlans as $ratePlan) {
                $this->syncService->syncRatePlan($ratePlan);
            }

            return \Success('Channex content synced');
        } catch (\Exception $e) {
            return \Failed($e->getMessage());
        }
    }
}
