<?php

namespace App\Http\Controllers;

use App\Http\Requests\Financial\FinancialDashboardRequest;
use App\Services\FinancialDashboardService;
use Carbon\Carbon;
use Exception;

class FinancialDashboardController extends Controller
{
    public function __construct(
        private FinancialDashboardService $financialDashboardService
    ) {
    }

    public function bounds()
    {
        try {
            return SuccessData('Financial bounds retrieved', $this->financialDashboardService->bounds());
        } catch (Exception $e) {
            return Failed($e->getMessage());
        }
    }

    public function show(FinancialDashboardRequest $request)
    {
        try {
            $start = Carbon::parse($request->validated()['start_date']);
            $end = Carbon::parse($request->validated()['end_date']);

            return SuccessData(
                'Financial dashboard retrieved',
                $this->financialDashboardService->build($start, $end)
            );
        } catch (Exception $e) {
            return Failed($e->getMessage());
        }
    }
}
