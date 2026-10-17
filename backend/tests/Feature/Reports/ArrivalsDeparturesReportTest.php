<?php

namespace Tests\Feature\Reports;

use App\Models\Reservation;
use App\Services\Reports\ReportQueryService;
use Tests\TestCase;

class ArrivalsDeparturesReportTest extends TestCase
{
    public function test_date_range_emits_separate_arrival_and_departure_rows(): void
    {
        $start = '2026-06-01';
        $end = '2026-06-30';

        $report = app(ReportQueryService::class)->run('arrivals-departures', [
            'start_date' => $start,
            'end_date' => $end,
        ]);

        $tableCount = Reservation::excludingCancelled()
            ->where('start_date', '<=', $end)
            ->where('expire_date', '>=', $start)
            ->count();

        $summary = collect($report['summary'])->pluck('value', 'label');
        $this->assertSame($tableCount, $summary->get('Reservations'));

        $types = collect($report['rows'])->pluck('movement_type');
        $this->assertTrue($types->contains('Arrival'));
        $this->assertTrue($types->contains('Departure'));
        $this->assertGreaterThanOrEqual($tableCount, $report['rows']);
        $this->assertSame(count($report['rows']), $summary->get('Total movements'));
    }

    public function test_april_only_range_lists_april_movements(): void
    {
        $report = app(ReportQueryService::class)->run('arrivals-departures', [
            'start_date' => '2026-04-01',
            'end_date' => '2026-04-30',
        ]);

        $aprilRows = collect($report['rows'])->filter(
            fn (array $row) => str_starts_with((string) ($row['movement_date'] ?? ''), '2026-04')
        );

        $this->assertGreaterThanOrEqual(4, $aprilRows->count(), 'April month report should list April arrival/departure rows.');

        $summary = collect($report['summary'])->pluck('value', 'label');
        $this->assertGreaterThanOrEqual(4, (int) $summary->get('Reservations'));
    }

    public function test_april_arrivals_appear_in_april_to_august_range(): void
    {
        $aprilReservation = Reservation::excludingCancelled()
            ->where('start_date', '>=', '2026-04-01')
            ->where('start_date', '<=', '2026-04-30')
            ->first();

        if ($aprilReservation === null) {
            $this->markTestSkipped('No April 2026 reservation in seed data.');
        }

        $report = app(ReportQueryService::class)->run('arrivals-departures', [
            'start_date' => '2026-04-01',
            'end_date' => '2026-08-31',
        ]);

        $aprilArrivals = collect($report['rows'])->filter(function (array $row) {
            return ($row['movement_type'] ?? '') === 'Arrival'
                && str_starts_with((string) ($row['movement_date'] ?? ''), '2026-04');
        });

        $this->assertTrue(
            $aprilArrivals->pluck('reservation_id')->contains($aprilReservation->id),
            'April check-in should produce an Arrival movement row in the range report.'
        );
    }

    public function test_single_day_includes_stayover_movements(): void
    {
        $day = '2026-06-16';

        $report = app(ReportQueryService::class)->run('arrivals-departures', [
            'start_date' => $day,
            'end_date' => $day,
        ]);

        $types = collect($report['rows'])->pluck('movement_type')->all();
        $this->assertContains('Stayover', $types);
    }

    public function test_pending_payment_reservation_included_in_period_report(): void
    {
        $pending = Reservation::excludingCancelled()
            ->where('reservation_status', Reservation::STATUS_PENDING_PAYMENT)
            ->where('start_date', '<=', '2026-06-30')
            ->where('expire_date', '>=', '2026-06-01')
            ->first();

        if ($pending === null) {
            $this->markTestSkipped('No pending-payment reservation overlapping June 2026 in seed data.');
        }

        $report = app(ReportQueryService::class)->run('arrivals-departures', [
            'start_date' => '2026-06-01',
            'end_date' => '2026-06-30',
        ]);

        $ids = collect($report['rows'])->pluck('reservation_id');
        $this->assertTrue($ids->contains($pending->id));
    }
}
