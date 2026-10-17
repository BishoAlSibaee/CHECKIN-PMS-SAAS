<?php

namespace App\Http\Controllers\Channex;

use App\Http\Controllers\Controller;
use App\Services\Channex\OtaAllocationService;
use Illuminate\Http\Request;

class OtaAllocationController extends Controller
{
    public function __construct(private OtaAllocationService $service) {}

    private function currentHotelId(Request $request): int
    {
        return (int) $request->attributes->get('currentHotelId');
    }

    public function index(Request $request)
    {
        try {
            $rows = $this->service->listForHotel($this->currentHotelId($request));

            return \SuccessData('OTA allocations retrieved', ['room_types' => $rows]);
        } catch (\Exception $e) {
            return \Failed($e->getMessage());
        }
    }

    public function update(Request $request)
    {
        $request->validate([
            'room_type_id' => 'required|integer|exists:room_types,id',
            'allocated_rooms' => 'required|integer|min:0',
        ]);

        try {
            $allocation = $this->service->setAllocation(
                $this->currentHotelId($request),
                (int) $request->room_type_id,
                (int) $request->allocated_rooms,
                auth()->id()
            );

            return \SuccessData('Allocation updated', $allocation);
        } catch (\Exception $e) {
            return \Failed($e->getMessage());
        }
    }
}
