<?php

namespace App\Services;

use App\Models\Reservation;
use App\Models\ReservationChangeLog;
use App\Models\User;

class ReservationChangeLogService
{
    /** @var list<string> */
    public const TRACKED_FIELDS = [
        'start_date',
        'expire_date',
        'reservation_status',
        'logedin',
        'login_time',
        'discount',
        'extras',
        'penalties',
        'subtotal',
        'taxes',
        'total',
        'paid_amount',
        'balance_due',
        'payment_amount',
        'room_numbers',
    ];

    public function snapshot(Reservation $reservation): array
    {
        $reservation->loadMissing(['reservationRooms.room', 'payments']);

        $roomNumbers = $reservation->reservationRooms
            ->map(fn ($row) => $row->room?->number)
            ->filter(fn ($n) => $n !== null && $n !== '')
            ->values()
            ->all();

        return [
            'start_date' => $reservation->start_date,
            'expire_date' => $reservation->expire_date,
            'reservation_status' => (int) $reservation->reservation_status,
            'logedin' => (int) $reservation->logedin,
            'login_time' => $reservation->login_time,
            'discount' => round((float) $reservation->discount, 2),
            'extras' => round((float) $reservation->extras, 2),
            'penalties' => round((float) $reservation->penalties, 2),
            'subtotal' => round((float) $reservation->subtotal, 2),
            'taxes' => round((float) $reservation->taxes, 2),
            'total' => round((float) $reservation->total, 2),
            'paid_amount' => round($reservation->paidNetAmount(), 2),
            'balance_due' => round($reservation->balanceDue(), 2),
            'room_numbers' => $roomNumbers,
        ];
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    public function recordIfChanged(
        int $reservationId,
        string $action,
        array $before,
        array $after,
        ?int $userId = null
    ): ?ReservationChangeLog {
        $changes = $this->diff($before, $after);
        if ($changes === []) {
            return null;
        }

        return $this->record($reservationId, $action, $before, $after, $changes, $userId);
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @param  array<string, array{old: mixed, new: mixed}>  $changes
     */
    public function record(
        int $reservationId,
        string $action,
        array $before,
        array $after,
        array $changes,
        ?int $userId = null
    ): ReservationChangeLog {
        return ReservationChangeLog::create([
            'reservation_id' => $reservationId,
            'user_id' => $userId ?? auth()->id(),
            'action' => $action,
            'old_values' => $before,
            'new_values' => $after,
            'changes' => $changes,
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listForReservation(int $reservationId, int $limit = 50): array
    {
        return ReservationChangeLog::query()
            ->with('user:id,name,job_number')
            ->where('reservation_id', $reservationId)
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get()
            ->map(fn (ReservationChangeLog $row) => $this->formatRow($row))
            ->all();
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @return array<string, array{old: mixed, new: mixed}>
     */
    public function diff(array $before, array $after): array
    {
        $keys = array_unique(array_merge(array_keys($before), array_keys($after)));
        $changes = [];

        foreach ($keys as $key) {
            $old = $before[$key] ?? null;
            $new = $after[$key] ?? null;
            if ($this->valuesEqual($old, $new)) {
                continue;
            }
            $changes[$key] = ['old' => $old, 'new' => $new];
        }

        return $changes;
    }

    /**
     * @return array<string, mixed>
     */
    private function formatRow(ReservationChangeLog $row): array
    {
        /** @var User|null $user */
        $user = $row->user;

        return [
            'id' => $row->id,
            'action' => $row->action,
            'user' => $user ? [
                'id' => $user->id,
                'name' => $user->name,
                'job_number' => $user->job_number,
            ] : null,
            'old_values' => $row->old_values ?? [],
            'new_values' => $row->new_values ?? [],
            'changes' => $row->changes ?? [],
            'created_at' => $row->created_at?->toDateTimeString(),
        ];
    }

    private function valuesEqual(mixed $old, mixed $new): bool
    {
        if (is_array($old) || is_array($new)) {
            return json_encode($old) === json_encode($new);
        }

        if (is_numeric($old) && is_numeric($new)) {
            return abs((float) $old - (float) $new) < 0.005;
        }

        return $old === $new;
    }
}
