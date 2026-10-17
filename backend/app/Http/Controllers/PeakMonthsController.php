<?php

namespace App\Http\Controllers;

use App\Models\PeakMonth;
use App\Http\Requests\PeakMonth\UpdatePeakMonthRequest;
use App\Http\Resources\PeakMonth\PeakMonthResource;

class PeakMonthsController extends Controller
{
    public function index()
    {
        try {
            $peakMonths = PeakMonth::all();
            return \SuccessData('Peak months fetched successfully', PeakMonthResource::collection($peakMonths));
        } catch (\Exception $e) {
            return \Failed($e->getMessage());
        }
    }

    public function updateCheck(UpdatePeakMonthRequest $request)
    {
        try {
            foreach ($request->months as $month) {
                PeakMonth::where('id', $month['id'])->update(['check' => $month['check']]);
            }

            return \Success('Peak months check values updated successfully');
        } catch (\Exception $e) {
            return \Failed($e->getMessage());
        }
    }
}
