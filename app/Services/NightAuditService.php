<?php

namespace App\Services;

use App\Mail\NightAuditSummaryMail;
use App\Models\Hotel;
use App\Models\Invoice;
use App\Models\NightAuditRun;
use App\Models\Reservation;
use App\Models\ZatcaInvoice;
use App\Services\Accounting\FinancialStatementService;
use App\Support\ReservationCashQuery;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\PermissionRegistrar;

/**
 * Daily reconciliation and exception report for one hotel — not a hard lock
 * on the operational day (nothing here blocks check-ins/outs or edits), just
 * a standing record of what needs a human look: guests who never got checked
 * out, a broken books entry, or an invoice ZATCA never accepted.
 */
class NightAuditService
{
    public function __construct(private FinancialStatementService $financialStatementService) {}

    public function runForHotel(int $hotelId, Carbon $date): NightAuditRun
    {
        $hotel = Hotel::findOrFail($hotelId);
        $reservationIds = $this->reservationIdsForHotel($hotelId);

        $overdueCheckouts = $this->overdueCheckouts($reservationIds, $date);
        [$cashCollected, $refundsTotal] = $this->cashMovementsToday($reservationIds, $date);
        $trialBalance = $this->financialStatementService->trialBalance($date, $date, $hotelId);
        $zatcaExceptions = $this->zatcaExceptionsToday($hotelId, $date);

        $exceptions = [
            'overdue_checkouts' => $overdueCheckouts->map(fn (Reservation $r) => [
                'reservation_id' => $r->id,
                'guest' => $r->guestDisplayName(),
                'room' => $r->reservationRooms->first()?->room?->number,
                'expire_date' => $r->expire_date,
                'days_overdue' => Carbon::parse($r->expire_date)->diffInDays($date),
            ])->values()->all(),
            'trial_balance_unbalanced' => ! ($trialBalance['totals']['balanced'] ?? true),
            'zatca_pending' => $zatcaExceptions->map(fn (Invoice $inv) => [
                'invoice_id' => $inv->id,
                'invoice_number' => $inv->invoice_number,
                'reservation_id' => $inv->reservation_id,
                'status' => $inv->zatcaInvoice?->status,
                'error_message' => $inv->zatcaInvoice?->error_message,
            ])->values()->all(),
        ];

        $exceptionsCount = count($exceptions['overdue_checkouts'])
            + ($exceptions['trial_balance_unbalanced'] ? 1 : 0)
            + count($exceptions['zatca_pending']);

        $metrics = [
            'cash_collected' => $cashCollected,
            'refunds_total' => $refundsTotal,
            'overdue_checkouts_count' => count($exceptions['overdue_checkouts']),
            'trial_balance_debit' => $trialBalance['totals']['debit'] ?? 0,
            'trial_balance_credit' => $trialBalance['totals']['credit'] ?? 0,
            'zatca_pending_count' => count($exceptions['zatca_pending']),
        ];

        $run = NightAuditRun::updateOrCreate(
            ['hotel_id' => $hotelId, 'audit_date' => $date->toDateString()],
            [
                'metrics' => $metrics,
                'exceptions' => $exceptions,
                'exceptions_count' => $exceptionsCount,
                'generated_at' => now(),
            ]
        );

        $this->sendSummaryEmail($hotel, $run);

        return $run;
    }

    /** @return Collection<int, int> */
    private function reservationIdsForHotel(int $hotelId): Collection
    {
        return DB::table('reservation_rooms')
            ->join('rooms', 'rooms.id', '=', 'reservation_rooms.room_id')
            ->join('buildings', 'buildings.id', '=', 'rooms.building_id')
            ->where('buildings.hotel_id', $hotelId)
            ->pluck('reservation_rooms.reservation_id')
            ->unique()
            ->values();
    }

    /** @param  Collection<int, int>  $reservationIds */
    private function overdueCheckouts(Collection $reservationIds, Carbon $date): Collection
    {
        if ($reservationIds->isEmpty()) {
            return collect();
        }

        return Reservation::query()
            ->whereIn('id', $reservationIds)
            ->where('reservation_status', Reservation::STATUS_CONFIRMED)
            ->where('logedin', Reservation::LOGEDIN_IN_HOUSE)
            ->where('expire_date', '<', $date->toDateString())
            ->with(['client', 'reservationRooms.room'])
            ->get();
    }

    /**
     * @param  Collection<int, int>  $reservationIds
     * @return array{0: float, 1: float}  [cash collected net of refunds, refunds total]
     */
    private function cashMovementsToday(Collection $reservationIds, Carbon $date): array
    {
        if ($reservationIds->isEmpty()) {
            return [0.0, 0.0];
        }

        $payments = (float) ReservationCashQuery::paymentQuery()
            ->whereIn('reservation_pay.reservation_id', $reservationIds)
            ->whereDate('reservation_pay.created_at', $date->toDateString())
            ->sum('reservation_pay.pay');

        $refunds = (float) ReservationCashQuery::refundQuery()
            ->whereIn('reservation_pay.reservation_id', $reservationIds)
            ->whereDate('reservation_pay.created_at', $date->toDateString())
            ->sum('reservation_pay.pay');

        return [round($payments - $refunds, 2), round($refunds, 2)];
    }

    private function zatcaExceptionsToday(int $hotelId, Carbon $date): Collection
    {
        return Invoice::query()
            ->where('hotel_id', $hotelId)
            ->where('type', Invoice::TYPE_TAX_INVOICE)
            ->whereDate('issued_at', $date->toDateString())
            ->whereHas('zatcaInvoice', fn ($q) => $q->whereIn('status', [
                ZatcaInvoice::STATUS_PENDING,
                ZatcaInvoice::STATUS_FAILED,
                ZatcaInvoice::STATUS_SKIPPED,
            ]))
            ->with('zatcaInvoice')
            ->get();
    }

    private function sendSummaryEmail(Hotel $hotel, NightAuditRun $run): void
    {
        try {
            $registrar = app(PermissionRegistrar::class);
            $previousTeamId = $registrar->getPermissionsTeamId();

            try {
                $registrar->setPermissionsTeamId($hotel->id);
                $recipients = $hotel->users()->get()->filter(fn ($u) => $u->hasPermissionTo('manage night audit'));
            } finally {
                $registrar->setPermissionsTeamId($previousTeamId);
            }

            if ($recipients->isEmpty()) {
                $recipients = $hotel->users;
            }

            foreach ($recipients as $user) {
                if (! empty($user->email)) {
                    Mail::to($user->email)->queue(new NightAuditSummaryMail($run, $hotel));
                }
            }
        } catch (\Throwable $e) {
            // A mail failure must never make the audit itself look like it failed —
            // the run is already persisted; only the notification is best-effort.
            Log::warning('Night audit summary email failed', [
                'hotel_id' => $hotel->id,
                'audit_date' => $run->audit_date,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
