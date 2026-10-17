<?php

namespace App\Http\Controllers;

use App\Models\AccountingPeriod;
use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
use App\Services\Accounting\AccountingPostingService;
use App\Services\Accounting\FinancialAuditService;
use App\Services\Accounting\FinancialStatementService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AccountingController extends Controller
{
    public function __construct(
        private FinancialStatementService $financialStatementService,
        private AccountingPostingService $postingService,
        private FinancialAuditService $auditService
    ) {
    }

    private function currentHotelId(Request $request): int
    {
        return (int) $request->attributes->get('currentHotelId');
    }

    public function chartOfAccounts(Request $request)
    {
        $start = Carbon::parse($request->input('start_date', now()->startOfMonth()));
        $end = Carbon::parse($request->input('end_date', now()->endOfMonth()));

        $accounts = ChartOfAccount::where('active', 1)->orderBy('code')->get();
        $balanceRows = $this->financialStatementService->accountBalances($start, $end, null, $this->currentHotelId($request));
        $balanceMap = $balanceRows->keyBy('account_id');

        $accounts = $accounts->map(function ($account) use ($balanceMap) {
            $row = $balanceMap->get($account->id);
            return [
                'id' => $account->id,
                'code' => $account->code,
                'name_en' => $account->name_en,
                'name_ar' => $account->name_ar,
                'type' => $account->type,
                'balance' => round($row->balance ?? 0, 2),
            ];
        });

        return SuccessData('Chart of accounts', ['accounts' => $accounts]);
    }

    public function journalEntries(Request $request)
    {
        $start = Carbon::parse($request->input('start_date', now()->startOfMonth()));
        $end = Carbon::parse($request->input('end_date', now()->endOfMonth()));

        $entries = JournalEntry::with('lines')
            ->where('hotel_id', $this->currentHotelId($request))
            ->whereBetween('entry_date', [$start->toDateString(), $end->toDateString()])
            ->orderByDesc('entry_date')
            ->limit(200)
            ->get()
            ->map(function ($entry) {
                return [
                    'id' => $entry->id,
                    'entry_date' => $entry->entry_date,
                    'reference' => $entry->reference,
                    'description' => $entry->description,
                    'total_debit' => round($entry->lines->sum('debit'), 2),
                    'total_credit' => round($entry->lines->sum('credit'), 2),
                ];
            });

        return SuccessData('Journal entries', ['entries' => $entries]);
    }

    public function storeJournalEntry(Request $request)
    {
        $validated = $request->validate([
            'entry_date' => 'required|date',
            'reference' => 'nullable|string|max:64',
            'description' => 'nullable|string|max:255',
            'lines' => 'required|array|min:2',
            'lines.*.account_id' => 'required|exists:chart_of_accounts,id',
            'lines.*.debit' => 'nullable|numeric|min:0',
            'lines.*.credit' => 'nullable|numeric|min:0',
        ]);

        $debit = collect($validated['lines'])->sum('debit');
        $credit = collect($validated['lines'])->sum('credit');
        if (round($debit, 2) !== round($credit, 2)) {
            return Failed('Journal entry must balance (debits must equal credits).', 422);
        }

        $hotelId = $this->currentHotelId($request);
        $entry = DB::transaction(function () use ($validated, $hotelId) {
            return $this->postingService->createManualEntry($validated, auth()->id(), $hotelId);
        });

        $this->auditService->log('journal_entry_created', 'journal_entry', $entry->id, [
            'reference' => $entry->reference,
            'entry_date' => $entry->entry_date,
        ]);

        return SuccessData('Journal entry created', ['entry' => $entry]);
    }

    public function closePeriod(Request $request)
    {
        $validated = $request->validate([
            'year' => 'required|integer',
            'month' => 'required|integer|min:1|max:12',
        ]);

        $period = AccountingPeriod::firstOrCreate(
            ['hotel_id' => $this->currentHotelId($request), 'year' => $validated['year'], 'month' => $validated['month']],
            ['status' => 'open']
        );

        if ($period->status === 'closed') {
            return Failed('Period is already closed.', 422);
        }

        $period->update([
            'status' => 'closed',
            'closed_at' => now(),
            'closed_by' => auth()->id(),
        ]);

        $this->auditService->log('period_closed', 'accounting_period', $period->id, [
            'year' => $period->year,
            'month' => $period->month,
        ]);

        return Success('Accounting period closed successfully.');
    }
}
