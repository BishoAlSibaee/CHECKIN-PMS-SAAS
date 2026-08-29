<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Reservation;
use App\Models\ReservationPay;
use App\Services\Cache\CollectionsSummaryCache;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class CollectionsService
{
    private const TAX_RATE = 0.15;

    /**
     * @return array{
     *   total_balance: float,
     *   count: int,
     *   checkout_today_count: int,
     *   checkout_today_balance: float,
     *   in_house_count: int,
     *   overdue_count: int,
     *   overdue_balance: float,
     *   bucket_0_30: float,
     *   bucket_31_60: float,
     *   bucket_61_90: float,
     *   bucket_90_plus: float
     * }
     */
    public function summarize(?Carbon $asOf = null): array
    {
        $asOf = ($asOf ?? Carbon::today())->copy()->startOfDay();

        return CollectionsSummaryCache::remember($asOf, fn () => $this->buildSummary($asOf));
    }

    /**
     * @return array{
     *   total_balance: float,
     *   count: int,
     *   checkout_today_count: int,
     *   checkout_today_balance: float,
     *   in_house_count: int,
     *   overdue_count: int,
     *   overdue_balance: float,
     *   bucket_0_30: float,
     *   bucket_31_60: float,
     *   bucket_61_90: float,
     *   bucket_90_plus: float
     * }
     */
    private function buildSummary(Carbon $asOf): array
    {
        $rows = $this->mapOutstanding($this->outstandingQuery($asOf)->get(), $asOf);

        return [
            'total_balance' => round((float) $rows->sum('balance_due'), 2),
            'count' => $rows->count(),
            'checkout_today_count' => $rows->where('urgency', 'checkout_today')->count(),
            'checkout_today_balance' => round((float) $rows->where('urgency', 'checkout_today')->sum('balance_due'), 2),
            'in_house_count' => $rows->where('urgency', 'in_house')->count(),
            'overdue_count' => $rows->where('urgency', 'overdue')->count(),
            'overdue_balance' => round((float) $rows->where('urgency', 'overdue')->sum('balance_due'), 2),
            'bucket_0_30' => round((float) $rows->where('aging_bucket', '0-30')->sum('balance_due'), 2),
            'bucket_31_60' => round((float) $rows->where('aging_bucket', '31-60')->sum('balance_due'), 2),
            'bucket_61_90' => round((float) $rows->where('aging_bucket', '61-90')->sum('balance_due'), 2),
            'bucket_90_plus' => round((float) $rows->where('aging_bucket', '90+')->sum('balance_due'), 2),
        ];
    }

    /**
     * @return array{items: Collection<int, array>, total: int}
     */
    public function list(
        ?Carbon $asOf,
        string $tab = 'all',
        ?string $search = null,
        string $sort = 'balance_due',
        int $page = 1,
        int $perPage = 15
    ): array {
        $asOf = ($asOf ?? Carbon::today())->copy()->startOfDay();
        $query = $this->outstandingQuery($asOf);

        if ($search !== null && trim($search) !== '') {
            $term = trim($search);
            $query->where(function (Builder $q) use ($term) {
                if (preg_match('/^\d+$/', $term)) {
                    $q->where('reservations.id', (int) $term);
                }
                $q->orWhereHas('client', function (Builder $clientQuery) use ($term) {
                    $clientQuery->where('first_name', 'like', "%{$term}%")
                        ->orWhere('last_name', 'like', "%{$term}%")
                        ->orWhere('mobile', 'like', "%{$term}%")
                        ->orWhere('email', 'like', "%{$term}%");
                });
                $q->orWhereHas('reservationRooms.room', function (Builder $roomQuery) use ($term) {
                    $roomQuery->where('number', 'like', "%{$term}%");
                });
            });
        }

        $mapped = $this->mapOutstanding($query->get(), $asOf);
        $filtered = $this->filterTab($mapped, $tab, $asOf);

        $filtered = $this->sortRows($filtered, $sort);

        $total = $filtered->count();
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $items = $filtered->slice(($page - 1) * $perPage, $perPage)->values();

        return [
            'items' => $items,
            'total' => $total,
            'current_page' => $page,
            'per_page' => $perPage,
            'last_page' => (int) max(1, ceil($total / $perPage)),
        ];
    }

    private function outstandingQuery(Carbon $asOf): Builder
    {
        return Reservation::query()
            ->with(['client', 'payments', 'reservationRooms.room'])
            ->outstandingForAging();
    }

    /**
     * @param  Collection<int, Reservation>  $reservations
     * @return Collection<int, array<string, mixed>>
     */
    private function mapOutstanding(Collection $reservations, Carbon $asOf): Collection
    {
        return $reservations->map(function (Reservation $reservation) use ($asOf) {
            $paidNet = $this->paidNet($reservation);
            $total = $this->reservationTotal($reservation);
            $balanceDue = round(max(0, $total - $paidNet), 2);

            if ($balanceDue <= 0.005) {
                return null;
            }

            $daysOverdue = $this->daysOverdue($reservation, $asOf);
            $urgency = $this->resolveUrgency($reservation, $asOf, $daysOverdue);
            $paidPercent = $total > 0.005 ? round(min(100, ($paidNet / $total) * 100), 1) : 0.0;

            $roomNumbers = $reservation->reservationRooms
                ->map(fn ($row) => $row->room?->number)
                ->filter(fn ($n) => $n !== null && $n !== '')
                ->values()
                ->all();

            return [
                'reservation_id' => $reservation->id,
                'guest' => $this->guestName($reservation->client),
                'client_id' => $reservation->client_id,
                'room' => $roomNumbers !== [] ? implode(', ', array_map('strval', $roomNumbers)) : '—',
                'start_date' => $reservation->start_date,
                'expire_date' => $reservation->expire_date,
                'logedin' => (int) $reservation->logedin,
                'reservation_status' => (int) $reservation->reservation_status,
                'total' => round($total, 2),
                'paid_net' => round($paidNet, 2),
                'balance_due' => $balanceDue,
                'paid_percent' => $paidPercent,
                'days_overdue' => $daysOverdue,
                'aging_bucket' => $this->agingBucket($daysOverdue),
                'urgency' => $urgency,
                'guest_phone' => $reservation->client?->mobile ?? '',
            ];
        })->filter()->values();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return Collection<int, array<string, mixed>>
     */
    private function filterTab(Collection $rows, string $tab, Carbon $asOf): Collection
    {
        $today = $asOf->toDateString();

        $filtered = match ($tab) {
            'checkout_today' => $rows->filter(fn (array $row) => $row['expire_date'] === $today),
            'in_house' => $rows->filter(fn (array $row) => $row['urgency'] === 'in_house'),
            'overdue' => $rows->filter(fn (array $row) => $row['urgency'] === 'overdue'),
            'upcoming' => $rows->filter(fn (array $row) => $row['urgency'] === 'upcoming'),
            default => $rows,
        };

        return $filtered->values();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return Collection<int, array<string, mixed>>
     */
    private function sortRows(Collection $rows, string $sort): Collection
    {
        return match ($sort) {
            'expire_date' => $rows->sortBy('expire_date')->values(),
            'guest' => $rows->sortBy('guest', SORT_NATURAL | SORT_FLAG_CASE)->values(),
            'days_overdue' => $rows->sortByDesc('days_overdue')->values(),
            default => $rows->sortByDesc('balance_due')->values(),
        };
    }

    private function resolveUrgency(Reservation $reservation, Carbon $asOf, int $daysOverdue): string
    {
        $today = $asOf->toDateString();

        if ($reservation->expire_date === $today) {
            return 'checkout_today';
        }

        if ($daysOverdue > 0) {
            return 'overdue';
        }

        if ($reservation->start_date > $today) {
            return 'upcoming';
        }

        if ($this->isActuallyInHouse($reservation, $asOf)) {
            return 'in_house';
        }

        return 'open';
    }

    private function isActuallyInHouse(Reservation $reservation, Carbon $asOf): bool
    {
        $today = $asOf->toDateString();

        if ((int) $reservation->logedin !== Reservation::LOGEDIN_IN_HOUSE) {
            return false;
        }

        return $reservation->start_date <= $today;
    }

    private function reservationTotal(Reservation $reservation): float
    {
        $total = (float) $reservation->total;

        if ($total <= 0 && (float) $reservation->subtotal > 0) {
            $total = round((float) $reservation->subtotal * (1 + self::TAX_RATE), 2);
        }

        return $total;
    }

    private function paidNet(Reservation $reservation): float
    {
        $paid = (float) $reservation->payments
            ->where('type', ReservationPay::TYPE_PAYMENT)
            ->sum('pay');
        $refunded = (float) $reservation->payments
            ->where('type', ReservationPay::TYPE_REFUND)
            ->sum('pay');

        return round($paid - $refunded, 2);
    }

    private function daysOverdue(Reservation $reservation, Carbon $asOf): int
    {
        $expire = Carbon::parse($reservation->expire_date)->startOfDay();

        if ($expire->gte($asOf)) {
            return 0;
        }

        return (int) $expire->diffInDays($asOf);
    }

    private function agingBucket(int $daysOverdue): string
    {
        return match (true) {
            $daysOverdue <= 30 => '0-30',
            $daysOverdue <= 60 => '31-60',
            $daysOverdue <= 90 => '61-90',
            default => '90+',
        };
    }

    private function guestName(?Client $client): string
    {
        if (! $client) {
            return '—';
        }

        return trim(($client->first_name ?? '').' '.($client->last_name ?? '')) ?: '—';
    }
}
