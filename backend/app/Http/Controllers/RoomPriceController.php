<?php

namespace App\Http\Controllers;

use App\Http\Requests\RoomPrice\AddMaxMinMonthRequest;
use App\Http\Requests\RoomPriceMaxMonth\StoreRoomPriceMaxMonthRequest;
use App\Models\RoomPrice;
use App\Models\RoomPriceMaxMonth;
use Exception;

class RoomPriceController extends Controller
{
    public function updateMaxMinMonth(AddMaxMinMonthRequest $request, int $id)
    {
        try {
            $roomPrice = RoomPrice::findOrFail($id);
            $roomPrice->update($request->validated());
            return SuccessData('Max/min month updated successfully.', $roomPrice->fresh());
        } catch (Exception $e) {
            return Failed($e->getMessage());
        }
    }

    public function addMaxMonthPricing(StoreRoomPriceMaxMonthRequest $request)
    {
        try {
            $maxMonth = RoomPriceMaxMonth::create($request->validated());
            return SuccessData('Max month pricing added successfully.', $maxMonth->load('roomPrice'));
        } catch (Exception $e) {
            return Failed($e->getMessage());
        }
    }
}

