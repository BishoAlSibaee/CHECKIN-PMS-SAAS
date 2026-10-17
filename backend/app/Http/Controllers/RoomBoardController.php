<?php

namespace App\Http\Controllers;

use App\Services\OccupancyBoardCache;
use App\Services\RoomOccupancyService;
use Carbon\Carbon;
use Illuminate\Http\Request;

class RoomBoardController extends Controller
{
    public function __construct(private RoomOccupancyService $occupancyService)
    {
    }

    public function occupancyBoard(Request $request)
    {
        try {
            $date = $request->filled('date')
                ? Carbon::parse($request->date)->startOfDay()
                : Carbon::today();

            $filters = array_filter([
                'building_id' => $request->input('building_id'),
                'floor_id' => $request->input('floor_id'),
                'suite_id' => $request->input('suite_id'),
                'occupancy_status' => $request->input('occupancy_status'),
                'search' => $request->input('search'),
            ], fn ($v) => $v !== null && $v !== '');

            $board = OccupancyBoardCache::remember($date, $filters, fn () => $this->occupancyService->buildBoard($date, $filters));

            return \SuccessData('Room occupancy board retrieved', $board);
        } catch (\Exception $e) {
            return \Failed($e->getMessage());
        }
    }
}
