<?php

namespace App\Http\Controllers;

use App\Services\CollectionsService;
use App\Services\ReservationFinancialService;
use Carbon\Carbon;
use Illuminate\Http\Request;

class CollectionsController extends Controller
{
    public function __construct(
        private CollectionsService $collectionsService
    ) {}

    /**
     * KPI summary for outstanding guest balances.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function summary()
    {
        try {
            return \SuccessData(
                'Collections summary retrieved',
                $this->collectionsService->summarize(Carbon::today())
            );
        } catch (\Exception $e) {
            return \Failed($e->getMessage());
        }
    }

    /**
     * Paginated outstanding balances with tab filters.
     *
     * Query: tab (all|checkout_today|in_house|overdue|upcoming), search, sort, page, per_page
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        try {
            $tab = (string) $request->input('tab', 'all');
            $allowedTabs = ['all', 'checkout_today', 'in_house', 'overdue', 'upcoming'];
            if (! in_array($tab, $allowedTabs, true)) {
                $tab = 'all';
            }

            $sort = (string) $request->input('sort', 'balance_due');
            $allowedSort = ['balance_due', 'expire_date', 'guest', 'days_overdue'];
            if (! in_array($sort, $allowedSort, true)) {
                $sort = 'balance_due';
            }

            $result = $this->collectionsService->list(
                Carbon::today(),
                $tab,
                $request->filled('search') ? (string) $request->input('search') : null,
                $sort,
                max(1, (int) $request->input('page', 1)),
                \returnPerPage()
            );

            return \SuccessData('Collections retrieved', [
                'items' => $result['items'],
                'total' => $result['total'],
                'current_page' => $result['current_page'],
                'per_page' => $result['per_page'],
                'last_page' => $result['last_page'],
                'tab' => $tab,
            ]);
        } catch (\Exception $e) {
            return \Failed($e->getMessage());
        }
    }

    /**
     * Collect remaining balances for all reservations with amount due.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function collectAll()
    {
        try {
            $result = app(ReservationFinancialService::class)->collectAllOutstanding((int) auth()->id());

            if (! empty($result['error'])) {
                return \Failed($result['error'], 429);
            }

            $summary = $this->collectionsService->summarize(Carbon::today());
            $result['remaining_count'] = (int) $summary['count'];
            $result['remaining_total'] = (float) $summary['total_balance'];

            return \SuccessData('Outstanding balances collected', $result);
        } catch (\Exception $e) {
            return \Failed($e->getMessage());
        }
    }
}
