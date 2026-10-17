<?php

namespace App\Services\Accounting;

use App\Models\ChartOfAccount;
use App\Models\JournalEntryLine;
use App\Models\ReservationDailyCharge;
use App\Models\ReservationPay;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class FinancialStatementService
{
    /**
     * Period or cumulative balances per account from journal lines joined to chart of accounts.
     *
     * @return Collection<int, object{account_id: int, code: string, name: string, type: string, total_debit: float, total_credit: float, balance: float}>
     */
    public function accountBalances(?Carbon $start, ?Carbon $end, ?Carbon $asOf = null, ?int $hotelId = null): Collection
    {
        $query = JournalEntryLine::query()
            ->join('journal_entries', 'journal_entry_lines.journal_entry_id', '=', 'journal_entries.id')
            ->join('chart_of_accounts', 'journal_entry_lines.account_id', '=', 'chart_of_accounts.id')
            ->where('chart_of_accounts.active', true)
            ->selectRaw('chart_of_accounts.id as account_id')
            ->selectRaw('chart_of_accounts.code')
            ->selectRaw('chart_of_accounts.name_en as name')
            ->selectRaw('chart_of_accounts.type')
            ->selectRaw('COALESCE(SUM(journal_entry_lines.debit), 0) as total_debit')
            ->selectRaw('COALESCE(SUM(journal_entry_lines.credit), 0) as total_credit')
            ->groupBy(
                'chart_of_accounts.id',
                'chart_of_accounts.code',
                'chart_of_accounts.name_en',
                'chart_of_accounts.type'
            );

        if ($hotelId !== null) {
            $query->where('journal_entries.hotel_id', $hotelId);
        }

        if ($asOf !== null) {
            $query->where('journal_entries.entry_date', '<=', $asOf->toDateString());
        } elseif ($start !== null && $end !== null) {
            $query->whereBetween('journal_entries.entry_date', [
                $start->toDateString(),
                $end->toDateString(),
            ]);
        }

        return $query->get()->map(function ($row) {
            $debit = round((float) $row->total_debit, 2);
            $credit = round((float) $row->total_credit, 2);

            return (object) [
                'account_id' => (int) $row->account_id,
                'code' => $row->code,
                'name' => $row->name,
                'type' => $row->type,
                'total_debit' => $debit,
                'total_credit' => $credit,
                'balance' => $this->signedBalance($row->type, $debit, $credit),
            ];
        });
    }

    public function trialBalance(Carbon $start, Carbon $end, ?int $hotelId = null): array
    {
        $accounts = $this->accountBalances($start, $end, null, $hotelId);
        $rows = $accounts
            ->filter(fn ($a) => $a->total_debit > 0 || $a->total_credit > 0)
            ->sortBy('code')
            ->values()
            ->map(fn ($a) => [
                'account_id' => $a->account_id,
                'code' => $a->code,
                'name' => $a->name,
                'type' => $a->type,
                'debit' => $a->total_debit,
                'credit' => $a->total_credit,
                'balance' => $a->balance,
            ])
            ->all();

        $totalDebit = round(collect($rows)->sum('debit'), 2);
        $totalCredit = round(collect($rows)->sum('credit'), 2);

        return [
            'period' => [
                'start' => $start->toDateString(),
                'end' => $end->toDateString(),
            ],
            'accounts' => $rows,
            'totals' => [
                'debit' => $totalDebit,
                'credit' => $totalCredit,
                'balanced' => abs($totalDebit - $totalCredit) < 0.02,
            ],
        ];
    }

    public function profitAndLoss(Carbon $start, Carbon $end, ?int $hotelId = null): array
    {
        $accounts = $this->accountBalances($start, $end, null, $hotelId)
            ->filter(fn ($a) => in_array($a->type, ['revenue', 'expense'], true));

        $revenue = $accounts->where('type', 'revenue')->values()->map(fn ($a) => [
            'account_id' => $a->account_id,
            'code' => $a->code,
            'name' => $a->name,
            'amount' => $a->balance,
        ])->all();

        $expenses = $accounts->where('type', 'expense')->values()->map(fn ($a) => [
            'account_id' => $a->account_id,
            'code' => $a->code,
            'name' => $a->name,
            'amount' => $a->balance,
        ])->all();

        $totalRevenue = round(collect($revenue)->sum('amount'), 2);
        $totalExpenses = round(collect($expenses)->sum('amount'), 2);
        $netIncome = round($totalRevenue - $totalExpenses, 2);

        return [
            'period' => [
                'start' => $start->toDateString(),
                'end' => $end->toDateString(),
            ],
            'revenue' => $revenue,
            'expenses' => $expenses,
            'totals' => [
                'revenue' => $totalRevenue,
                'expenses' => $totalExpenses,
                'net_income' => $netIncome,
            ],
        ];
    }

    public function balanceSheet(Carbon $asOfDate, ?int $hotelId = null): array
    {
        $accounts = $this->accountBalances(null, null, $asOfDate, $hotelId)
            ->filter(fn ($a) => in_array($a->type, ['asset', 'liability', 'equity'], true));

        $assets = $this->mapBalanceSheetSection($accounts->where('type', 'asset'));
        $liabilities = $this->mapBalanceSheetSection($accounts->where('type', 'liability'));
        $equity = $this->mapBalanceSheetSection($accounts->where('type', 'equity'));

        $netIncome = $this->cumulativeNetIncome($asOfDate, $hotelId);
        if (abs($netIncome) >= 0.005) {
            $equity = $this->mergeNetIncomeIntoEquity($equity, $netIncome);
        }

        $totalAssets = round(collect($assets)->sum('balance'), 2);
        $totalLiabilities = round(collect($liabilities)->sum('balance'), 2);
        $totalEquity = round(collect($equity)->sum('balance'), 2);

        return [
            'as_of' => $asOfDate->toDateString(),
            'assets' => $assets,
            'liabilities' => $liabilities,
            'equity' => $equity,
            'totals' => [
                'assets' => $totalAssets,
                'liabilities' => $totalLiabilities,
                'equity' => $totalEquity,
                'net_income_included' => round($netIncome, 2),
                'liabilities_and_equity' => round($totalLiabilities + $totalEquity, 2),
                'balanced' => abs($totalAssets - ($totalLiabilities + $totalEquity)) < 0.02,
            ],
        ];
    }

    /**
     * Revenue and expense accounts are closed into retained earnings for balance-sheet presentation.
     */
    private function cumulativeNetIncome(Carbon $asOfDate, ?int $hotelId = null): float
    {
        $accounts = $this->accountBalances(null, null, $asOfDate, $hotelId)
            ->filter(fn ($a) => in_array($a->type, ['revenue', 'expense'], true));

        $revenue = round($accounts->where('type', 'revenue')->sum('balance'), 2);
        $expenses = round($accounts->where('type', 'expense')->sum('balance'), 2);

        return round($revenue - $expenses, 2);
    }

    /**
     * @param  array<int, array{account_id: int, code: string, name: string, balance: float}>  $equity
     * @return array<int, array{account_id: int, code: string, name: string, balance: float}>
     */
    private function mergeNetIncomeIntoEquity(array $equity, float $netIncome): array
    {
        $merged = false;
        foreach ($equity as &$row) {
            if ($row['code'] === '3000') {
                $row['balance'] = round($row['balance'] + $netIncome, 2);
                $merged = true;
                break;
            }
        }
        unset($row);

        if (! $merged) {
            $retained = ChartOfAccount::query()->where('code', '3000')->first();
            $equity[] = [
                'account_id' => $retained?->id ?? 0,
                'code' => '3000',
                'name' => $retained?->name_en ?? 'Retained Earnings',
                'balance' => $netIncome,
            ];
        }

        return collect($equity)->sortBy('code')->values()->all();
    }

    public function cashFlow(Carbon $start, Carbon $end, ?int $hotelId = null): array
    {
        $cashAccounts = ChartOfAccount::query()
            ->where('active', true)
            ->where('type', 'asset')
            ->where(function ($q) {
                $q->where('code', 'like', '10%')
                    ->orWhere('name_en', 'like', '%cash%')
                    ->orWhere('name_en', 'like', '%bank%');
            })
            ->pluck('id');

        $lines = JournalEntryLine::query()
            ->join('journal_entries', 'journal_entry_lines.journal_entry_id', '=', 'journal_entries.id')
            ->join('chart_of_accounts', 'journal_entry_lines.account_id', '=', 'chart_of_accounts.id')
            ->whereIn('journal_entry_lines.account_id', $cashAccounts)
            ->whereBetween('journal_entries.entry_date', [
                $start->toDateString(),
                $end->toDateString(),
            ])
            ->when($hotelId !== null, fn ($q) => $q->where('journal_entries.hotel_id', $hotelId))
            ->selectRaw('chart_of_accounts.id as account_id')
            ->selectRaw('chart_of_accounts.code')
            ->selectRaw('chart_of_accounts.name_en as name')
            ->selectRaw('COALESCE(SUM(journal_entry_lines.debit), 0) as total_debit')
            ->selectRaw('COALESCE(SUM(journal_entry_lines.credit), 0) as total_credit')
            ->groupBy('chart_of_accounts.id', 'chart_of_accounts.code', 'chart_of_accounts.name_en')
            ->get();

        $accounts = $lines->map(function ($row) {
            $inflow = round((float) $row->total_debit, 2);
            $outflow = round((float) $row->total_credit, 2);

            return [
                'account_id' => (int) $row->account_id,
                'code' => $row->code,
                'name' => $row->name,
                'inflow' => $inflow,
                'outflow' => $outflow,
                'net' => round($inflow - $outflow, 2),
            ];
        })->values()->all();

        $totalInflow = round(collect($accounts)->sum('inflow'), 2);
        $totalOutflow = round(collect($accounts)->sum('outflow'), 2);

        return [
            'period' => [
                'start' => $start->toDateString(),
                'end' => $end->toDateString(),
            ],
            'accounts' => $accounts,
            'totals' => [
                'inflow' => $totalInflow,
                'outflow' => $totalOutflow,
                'net_change' => round($totalInflow - $totalOutflow, 2),
            ],
        ];
    }

    public function generalLedger(
        Carbon $start,
        Carbon $end,
        ?int $accountId = null,
        ?string $accountType = null,
        ?\App\Services\Reports\AccountingReportFilters $filters = null,
        ?int $hotelId = null
    ): array {
        $query = JournalEntryLine::query()
            ->join('journal_entries', 'journal_entry_lines.journal_entry_id', '=', 'journal_entries.id')
            ->join('chart_of_accounts', 'journal_entry_lines.account_id', '=', 'chart_of_accounts.id')
            ->whereBetween('journal_entries.entry_date', [
                $start->toDateString(),
                $end->toDateString(),
            ])
            ->when($hotelId !== null, fn ($q) => $q->where('journal_entries.hotel_id', $hotelId))
            ->orderBy('journal_entries.entry_date')
            ->orderBy('journal_entries.id')
            ->orderBy('journal_entry_lines.id')
            ->select([
                'journal_entry_lines.id as line_id',
                'journal_entries.id as entry_id',
                'journal_entries.entry_date',
                'journal_entries.reference',
                'journal_entries.description as entry_description',
                'journal_entry_lines.account_id',
                'chart_of_accounts.code as account_code',
                'chart_of_accounts.name_en as account_name',
                'chart_of_accounts.type as account_type',
                'journal_entry_lines.debit',
                'journal_entry_lines.credit',
                'journal_entry_lines.memo',
                'journal_entry_lines.reservation_id',
                'journal_entry_lines.client_id',
                'journal_entries.source_type',
                'journal_entries.source_id',
            ]);

        if ($accountId !== null) {
            $query->where('journal_entry_lines.account_id', $accountId);
        } elseif ($accountType !== null) {
            $query->where('chart_of_accounts.type', $accountType);
        }

        if ($filters !== null) {
            if ($filters->reservationId !== null) {
                $query->where('journal_entry_lines.reservation_id', $filters->reservationId);
            }

            if ($filters->search !== null) {
                $term = '%'.$filters->search.'%';
                $referenceTerms = AccountingPostingService::accrualReferenceSearchTerms($filters->search);
                $query->where(function ($builder) use ($term, $referenceTerms) {
                    foreach ($referenceTerms as $referenceTerm) {
                        $builder->orWhere('journal_entries.reference', 'like', '%'.$referenceTerm.'%');
                    }
                    $builder->orWhere('journal_entries.description', 'like', $term)
                        ->orWhere('chart_of_accounts.code', 'like', $term)
                        ->orWhere('chart_of_accounts.name_en', 'like', $term)
                        ->orWhere('chart_of_accounts.name_ar', 'like', $term)
                        ->orWhere('journal_entry_lines.memo', 'like', $term);
                });
            }

            if ($filters->minAmount !== null) {
                $query->where(function ($builder) use ($filters) {
                    $builder->where('journal_entry_lines.debit', '>=', $filters->minAmount)
                        ->orWhere('journal_entry_lines.credit', '>=', $filters->minAmount);
                });
            }

            if ($filters->maxAmount !== null) {
                $query->where(function ($builder) use ($filters) {
                    $builder->where(function ($inner) use ($filters) {
                        $inner->where('journal_entry_lines.debit', '>', 0)
                            ->where('journal_entry_lines.debit', '<=', $filters->maxAmount);
                    })->orWhere(function ($inner) use ($filters) {
                        $inner->where('journal_entry_lines.credit', '>', 0)
                            ->where('journal_entry_lines.credit', '<=', $filters->maxAmount);
                    });
                });
            }
        }

        $lines = $query->get()->map(function ($line) {
            return [
                'line_id' => (int) $line->line_id,
                'entry_id' => (int) $line->entry_id,
                'entry_date' => $line->entry_date,
                'reference' => $line->reference,
                'description' => $line->entry_description,
                'account_id' => (int) $line->account_id,
                'account_code' => $line->account_code,
                'account_name' => $line->account_name,
                'account_type' => $line->account_type,
                'debit' => round((float) $line->debit, 2),
                'credit' => round((float) $line->credit, 2),
                'memo' => $line->memo,
                'reservation_id' => $this->resolveReservationIdForLedgerLine($line),
                'client_id' => $line->client_id ? (int) $line->client_id : null,
            ];
        })->all();

        $totalDebit = round(collect($lines)->sum('debit'), 2);
        $totalCredit = round(collect($lines)->sum('credit'), 2);

        return [
            'period' => [
                'start' => $start->toDateString(),
                'end' => $end->toDateString(),
            ],
            'account_id' => $accountId,
            'account_type' => $accountType,
            'lines' => $lines,
            'totals' => [
                'debit' => $totalDebit,
                'credit' => $totalCredit,
            ],
        ];
    }

    private function signedBalance(string $type, float $debit, float $credit): float
    {
        return match ($type) {
            'asset', 'expense' => round($debit - $credit, 2),
            default => round($credit - $debit, 2),
        };
    }

    private function resolveReservationIdForLedgerLine(object $line): ?int
    {
        if (! empty($line->reservation_id)) {
            return (int) $line->reservation_id;
        }

        $sourceType = (string) ($line->source_type ?? '');
        $sourceId = (int) ($line->source_id ?? 0);

        if ($sourceType === 'reservation_pay' && $sourceId > 0) {
            $reservationId = ReservationPay::query()->whereKey($sourceId)->value('reservation_id');

            return $reservationId ? (int) $reservationId : null;
        }

        if ($sourceType === 'daily_charge' && $sourceId > 0) {
            $reservationId = ReservationDailyCharge::query()->whereKey($sourceId)->value('reservation_id');

            return $reservationId ? (int) $reservationId : null;
        }

        $reference = (string) ($line->reference ?? '');
        if (preg_match('/^PAY-(\d+)/', $reference, $matches) === 1) {
            $reservationId = ReservationPay::query()->whereKey((int) $matches[1])->value('reservation_id');

            return $reservationId ? (int) $reservationId : null;
        }

        return null;
    }

    /**
     * @param  Collection<int, object>  $accounts
     * @return array<int, array{account_id: int, code: string, name: string, balance: float}>
     */
    private function mapBalanceSheetSection(Collection $accounts): array
    {
        return $accounts
            ->filter(fn ($a) => abs($a->balance) >= 0.005)
            ->sortBy('code')
            ->values()
            ->map(fn ($a) => [
                'account_id' => $a->account_id,
                'code' => $a->code,
                'name' => $a->name,
                'balance' => $a->balance,
            ])
            ->all();
    }
}
