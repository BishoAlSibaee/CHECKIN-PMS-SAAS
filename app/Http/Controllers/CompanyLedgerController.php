<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Services\Accounting\AccountingPostingService;
use Carbon\Carbon;
use Illuminate\Http\Request;

class CompanyLedgerController extends Controller
{
    public function __construct(private AccountingPostingService $postingService) {}

    public function index(Request $request)
    {
        try {
            $currentHotelId = $request->attributes->get('currentHotelId');
            $start = $request->filled('start_date') ? Carbon::parse($request->start_date) : null;
            $end = $request->filled('end_date') ? Carbon::parse($request->end_date) : null;

            $companies = Company::query()
                ->when($currentHotelId, fn ($q) => $q->where('hotel_id', $currentHotelId))
                ->get()
                ->map(function (Company $company) use ($start, $end) {
                    $statement = $this->postingService->statementForCompany((int) $company->id, $start, $end);

                    return [
                        'company_id' => $company->id,
                        'name' => $company->name,
                        'credit_limit' => $company->credit_limit,
                        'status' => $company->status,
                        'balance' => $statement['balance'],
                        'total_debit' => $statement['total_debit'],
                        'total_credit' => $statement['total_credit'],
                    ];
                });

            return \SuccessData('Company ledger retrieved', ['companies' => $companies]);
        } catch (\Exception $e) {
            return \Failed($e->getMessage());
        }
    }

    public function statement(Request $request, int $companyId)
    {
        try {
            $start = $request->filled('start_date') ? Carbon::parse($request->start_date) : null;
            $end = $request->filled('end_date') ? Carbon::parse($request->end_date) : null;

            $statement = $this->postingService->statementForCompany($companyId, $start, $end);

            return \SuccessData('Company statement retrieved', $statement);
        } catch (\Exception $e) {
            return \Failed($e->getMessage());
        }
    }

    public function settle(Request $request, int $companyId)
    {
        $request->validate([
            'amount' => 'required|numeric|min:0.01',
        ]);

        try {
            $company = Company::findOrFail($companyId);
            $entry = $this->postingService->postCompanySettlement($company, (float) $request->amount, (int) auth()->id());

            return \SuccessData('Settlement recorded', $entry);
        } catch (\InvalidArgumentException $e) {
            return \Failed($e->getMessage(), 422);
        } catch (\Exception $e) {
            return \Failed($e->getMessage());
        }
    }
}
