<?php

namespace App\Http\Controllers;

use App\Http\Requests\RoomPriceMaxMonth\StoreRoomPriceMaxMonthRequest;
use App\Models\RoomPriceMaxMonth;
use Exception;
use Illuminate\Http\Request;

class RoomPriceMaxMonthController extends Controller
{
    public function store(StoreRoomPriceMaxMonthRequest $request)
    {
        try {
            $maxMonth = RoomPriceMaxMonth::create($request->validated());
            return SuccessData('Max month pricing added successfully.', $maxMonth->load('roomPrice'));
        } catch (Exception $e) {
            return Failed($e->getMessage());
        }
    }
}

