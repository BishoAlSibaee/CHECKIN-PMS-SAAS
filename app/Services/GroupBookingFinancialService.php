<?php

namespace App\Services;

use App\Models\GroupBooking;

/**
 * Pure summation over member reservations — never recomputes tax/discount,
 * only reads each Reservation's own already-correct total/paidNetAmount()/
 * balanceDue(). ReservationFinancialService has no multi-reservation
 * aggregation today, so this is a new, thin, additive service rather than a
 * modification to it.
 */
class GroupBookingFinancialService
{
    /** @return array<string, mixed> */
    public function aggregateFor(GroupBooking $groupBooking): array
    {
        $reservations = $groupBooking->reservations()->with(['payments', 'client'])->get();

        return [
            'member_count' => $reservations->count(),
            'total' => round((float) $reservations->sum(fn ($r) => (float) $r->total), 2),
            'paid' => round((float) $reservations->sum(fn ($r) => $r->paidNetAmount()), 2),
            'balance_due' => round((float) $reservations->sum(fn ($r) => $r->balanceDue()), 2),
            'by_status' => $reservations->groupBy('reservation_status')->map->count(),
            'members' => $reservations->map(fn ($r) => [
                'reservation_id' => $r->id,
                'client_name' => trim(($r->client?->first_name ?? '').' '.($r->client?->last_name ?? '')) ?: null,
                'start_date' => $r->start_date,
                'expire_date' => $r->expire_date,
                'total' => round((float) $r->total, 2),
                'paid' => round($r->paidNetAmount(), 2),
                'balance_due' => round($r->balanceDue(), 2),
                'reservation_status' => (int) $r->reservation_status,
            ])->values(),
        ];
    }
}
