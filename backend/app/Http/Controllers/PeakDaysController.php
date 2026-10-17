<?php

namespace App\Http\Controllers;

use App\Models\PeakDay;
use App\Http\Requests\PeakDay\UpdatePeakDayRequest;
use App\Http\Resources\PeakDay\PeakDayResource;

class PeakDaysController extends Controller
{
    public function index()
    {
        try {
            $peakDays = PeakDay::all();
            return \SuccessData('Peak days fetched successfully', PeakDayResource::collection($peakDays));
        } catch (\Exception $e) {
            return \Failed($e->getMessage());
        }
    }

    public function updateCheck(UpdatePeakDayRequest $request)
    {
        try {
            foreach ($request->days as $day) {
                PeakDay::where('id', $day['id'])->update(['check' => $day['check']]);
            }

            return \Success('Peak days check values updated successfully');
        } catch (\Exception $e) {
            return \Failed($e->getMessage());
        }
    }
}
