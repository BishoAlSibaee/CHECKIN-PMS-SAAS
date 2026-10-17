<?php

namespace App\Http\Controllers;

use App\Models\NightAuditRun;
use App\Services\NightAuditService;
use Carbon\Carbon;
use Illuminate\Http\Request;

class NightAuditController extends Controller
{
    public function __construct(private NightAuditService $service) {}

    private function currentHotelId(Request $request): int
    {
        return (int) $request->attributes->get('currentHotelId');
    }

    public function index(Request $request)
    {
        $runs = NightAuditRun::where('hotel_id', $this->currentHotelId($request))
            ->orderByDesc('audit_date')
            ->limit(60)
            ->get();

        return \SuccessData('Night audit history', ['runs' => $runs]);
    }

    public function store(Request $request)
    {
        $request->validate(['date' => 'nullable|date']);

        $date = $request->date ? Carbon::parse($request->date) : Carbon::today();

        try {
            $run = $this->service->runForHotel($this->currentHotelId($request), $date);

            return \SuccessData('Night audit run completed', ['run' => $run]);
        } catch (\Exception $e) {
            return \Failed($e->getMessage());
        }
    }
}
