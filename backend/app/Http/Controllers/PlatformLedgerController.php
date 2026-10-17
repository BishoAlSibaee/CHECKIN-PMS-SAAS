<?php

namespace App\Http\Controllers;

use App\Models\Reservation_source;
use App\Services\Accounting\AccountingPostingService;
use Carbon\Carbon;
use Illuminate\Http\Request;

class PlatformLedgerController extends Controller
{
    public function __construct(private AccountingPostingService $postingService) {}

    /** List every OTA-flagged source with its running balance, for the statement list view. */
    public function index(Request $request)
    {
        try {
            $start = $request->filled('start_date') ? Carbon::parse($request->start_date) : null;
            $end = $request->filled('end_date') ? Carbon::parse($request->end_date) : null;

            $platforms = Reservation_source::where('is_ota_channel', true)->get()->map(function (Reservation_source $source) use ($start, $end) {
                $statement = $this->postingService->statementForSource((int) $source->id, $start, $end);

                return [
                    'reservation_source_id' => $source->id,
                    'name_en' => $source->name_en,
                    'name_ar' => $source->name_ar,
                    'balance' => $statement['balance'],
                    'total_debit' => $statement['total_debit'],
                    'total_credit' => $statement['total_credit'],
                ];
            });

            return \SuccessData('Platform ledger retrieved', ['platforms' => $platforms]);
        } catch (\Exception $e) {
            return \Failed($e->getMessage());
        }
    }

    public function statement(Request $request, int $reservationSourceId)
    {
        try {
            $start = $request->filled('start_date') ? Carbon::parse($request->start_date) : null;
            $end = $request->filled('end_date') ? Carbon::parse($request->end_date) : null;

            $statement = $this->postingService->statementForSource($reservationSourceId, $start, $end);

            return \SuccessData('Platform statement retrieved', $statement);
        } catch (\Exception $e) {
            return \Failed($e->getMessage());
        }
    }
}
