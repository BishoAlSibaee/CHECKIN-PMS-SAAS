<?php

namespace Database\Seeders;

use App\Models\Client;
use App\Models\PeakDay;
use App\Models\PeakMonth;
use App\Models\Pricingplan;
use App\Models\Reservation;
use App\Models\Reservation_source;
use App\Models\ReservationDailyCharge;
use App\Models\ReservationPay;
use App\Models\ReservationRoom;
use App\Models\Room;
use App\Models\RoomPrice;
use App\Models\RoomType;
use App\Models\RoomtypePricingplan;
use App\Models\Stay_reason;
use App\Models\User;
use App\Services\PricingEngine;
use App\Services\RevenueAccrualService;
use App\Support\ReservationCashQuery;
use App\Support\ReservationRelatedCleanup;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Test reservations for financial / revenue reports.
 *
 * Run alone:  php artisan db:seed --class=ReservationTestDataSeeder
 * Demo flow: php artisan demo:setup --fresh
 */
class ReservationTestDataSeeder extends Seeder
{
    /** When true, purge step is skipped (e.g. already done by demo:reset-reservations). */
    protected bool $skipClearOnRun = false;

    private function demoYear(): int
    {
        return (int) env('RESERVATION_DEMO_YEAR', Carbon::today()->format('Y'));
    }

    protected function scenarios(): array
    {
        $y = $this->demoYear();

        return [
            ['start' => "{$y}-03-28", 'end' => "{$y}-04-03", 'rent_type' => 0, 'discount' => 0, 'pay' => 'full', 'status' => 1],
            ['start' => "{$y}-04-05", 'end' => "{$y}-04-12", 'rent_type' => 0, 'discount' => 0, 'pay' => 'full', 'status' => 1],
            ['start' => "{$y}-04-15", 'end' => "{$y}-04-22", 'rent_type' => 0, 'discount' => 50, 'pay' => 'partial', 'status' => 2],
            ['start' => "{$y}-04-20", 'end' => "{$y}-05-05", 'rent_type' => 0, 'discount' => 0, 'pay' => 'full', 'status' => 1],
            ['start' => "{$y}-05-10", 'end' => "{$y}-05-17", 'rent_type' => 0, 'discount' => 0, 'pay' => 'full', 'status' => 1],
            ['start' => "{$y}-06-01", 'end' => "{$y}-06-08", 'rent_type' => 0, 'discount' => 100, 'pay' => 'full', 'status' => 1],
            ['start' => "{$y}-06-12", 'end' => "{$y}-06-20", 'rent_type' => 0, 'discount' => 0, 'pay' => 'partial', 'status' => 2],
            ['start' => "{$y}-06-19", 'end' => "{$y}-07-04", 'rent_type' => 0, 'discount' => 50, 'pay' => 'full', 'status' => 1],
            ['start' => "{$y}-07-10", 'end' => "{$y}-07-17", 'rent_type' => 0, 'discount' => 0, 'pay' => 'full', 'status' => 1],
            ['start' => "{$y}-07-26", 'end' => "{$y}-08-09", 'rent_type' => 0, 'discount' => 0, 'pay' => 'full', 'status' => 1],
            ['start' => "{$y}-08-01", 'end' => "{$y}-08-15", 'rent_type' => 0, 'discount' => 200, 'pay' => 'full', 'status' => 1],
            ['start' => "{$y}-08-20", 'end' => "{$y}-09-03", 'rent_type' => 0, 'discount' => 0, 'pay' => 'none', 'status' => 2],
        ];
    }

    public function run(): void
    {
        $this->command?->info('Seeding test reservations...');

        $pricingEngine = app(PricingEngine::class);
        $revenueAccrual = app(RevenueAccrualService::class);

        $userId = User::first()?->id ?? 1;
        $stayReasonId = $this->ensureStayReason();
        $sourceId = $this->ensureReservationSource();
        $clients = $this->ensureClients();
        $roomType = $this->ensureRoomType();
        $this->ensurePricingPlan($roomType);
        $this->ensurePeakMonthsForPricingTests();
        $rooms = $this->ensureRooms($roomType);

        if ($rooms->isEmpty() || $clients->isEmpty()) {
            $this->command?->warn('ReservationTestDataSeeder: no rooms or clients — skipping scenarios.');

            return;
        }

        $this->enableFridayPeak();

        $shouldClear = \App\Support\ReservationTestDataGuard::mayPurgeDemoYearReservations()
            || ($this->command && ! $this->command->option('no-interaction')
                && $this->command->confirm('Remove existing test reservations ('.$this->demoYear().')?', true));

        if ($shouldClear && ! $this->skipClearOnRun) {
            $this->clearTestReservations();
        }

        $scenarios = $this->scenarios();

        $created = 0;
        $skipped = 0;

        DB::transaction(function () use (
            $scenarios,
            $rooms,
            $clients,
            $userId,
            $stayReasonId,
            $sourceId,
            $pricingEngine,
            $revenueAccrual,
            &$created,
            &$skipped
        ) {
            foreach ($scenarios as $index => $scenario) {
                $room = $rooms[$index % $rooms->count()];
                $client = $clients[$index % $clients->count()];

                if ($this->roomHasOverlap($room->id, $scenario['start'], $scenario['end'])) {
                    $this->command?->warn("Skip overlap: room {$room->id}, {$scenario['start']} – {$scenario['end']}");
                    $skipped++;

                    continue;
                }

                $this->createTestReservation(
                    $room,
                    $client->id,
                    $userId,
                    $stayReasonId,
                    $sourceId,
                    $scenario,
                    $pricingEngine,
                    $revenueAccrual
                );
                $created++;
            }
        });

        $this->command?->info("Done: {$created} reservation(s) created, {$skipped} skipped.");
        $year = $this->demoYear();
        $this->command?->table(
            ['Test report periods', 'Suggestion'],
            [
                ["April {$year}", "Arrivals & departures → {$year}-04-01 to {$year}-08-31"],
                ["May {$year}", "Financials → Reports → {$year}-05-01 to {$year}-05-31"],
                ["June–July {$year}", "Accrual revenue → {$year}-06-01 to {$year}-07-31"],
                ["August {$year} (partial stays)", "{$year}-08-01 to {$year}-08-31 — cross-month bookings"],
            ]
        );
    }

    private function clearTestReservations(): void
    {
        $ids = Reservation::whereYear('start_date', $this->demoYear())->pluck('id');

        if ($ids->isEmpty()) {
            return;
        }

        $accountingRows = ReservationRelatedCleanup::purgeAccounting($ids);
        ReservationDailyCharge::whereIn('reservation_id', $ids)->delete();
        ReservationPay::whereIn('reservation_id', $ids)->delete();
        ReservationRoom::whereIn('reservation_id', $ids)->delete();
        Reservation::whereIn('id', $ids)->delete();

        $this->command?->info('Removed '.$ids->count().' test reservation(s) from '.$this->demoYear()." ({$accountingRows} accounting row(s) purged).");
    }

    private function createTestReservation(
        Room $room,
        int $clientId,
        int $userId,
        int $stayReasonId,
        int $sourceId,
        array $scenario,
        PricingEngine $pricingEngine,
        RevenueAccrualService $revenueAccrual
    ): void {
        $startDate = Carbon::parse($scenario['start'])->startOfDay();
        $endDate = Carbon::parse($scenario['end'])->startOfDay();
        $nights = $startDate->diffInDays($endDate);
        $rentType = (int) $scenario['rent_type'];
        $priceMode = 0;

        $lines = $pricingEngine->buildDailyBreakdown(
            $room,
            $startDate->toDateString(),
            $endDate->toDateString(),
            $rentType,
            $priceMode
        );

        $basePrice = round(array_sum(array_column($lines, 'base_amount')), 2);
        $discount = min((float) ($scenario['discount'] ?? 0), max(0, $basePrice - 1));
        $extras = 0;
        $penalties = 0;
        $subtotal = $basePrice - $discount + $extras + $penalties;
        $taxes = round($subtotal * 0.15, 2, PHP_ROUND_HALF_UP);
        $total = round($subtotal + $taxes, 2);

        $payAmount = match ($scenario['pay'] ?? 'full') {
            'full' => $total,
            'partial' => round($total * 0.5, 2),
            default => 0,
        };

        $reservation = Reservation::create([
            'client_id' => $clientId,
            'start_date' => $startDate->toDateString(),
            'nights' => $nights,
            'expire_date' => $endDate->toDateString(),
            'reservation_type' => 0,
            'reservation_status' => $scenario['status'] ?? 1,
            'stay_reason_id' => $stayReasonId,
            'reservation_source_id' => $sourceId,
            'rent_type' => $rentType,
            'base_price' => $basePrice,
            'discount' => $discount,
            'extras' => $extras,
            'penalties' => $penalties,
            'subtotal' => $subtotal,
            'taxes' => $taxes,
            'total' => $total,
            'logedin' => 1,
            'login_time' => $startDate->toDateString(),
            'user_id' => $userId,
        ]);

        $finalRoomPrice = ($basePrice - $discount + $extras + $penalties) * 1.15;

        $resRoom = ReservationRoom::create([
            'reservation_id' => $reservation->id,
            'room_id' => $room->id,
            'suite_id' => null,
            'price' => round($finalRoomPrice, 2),
        ]);

        $revenueAccrual->persistDailyCharges(
            $reservation->id,
            $resRoom->id,
            $room->id,
            $rentType,
            $lines
        );

        $this->createRoomPriceSnapshot($room, $resRoom->id, $startDate, $endDate, $rentType);

        if ($payAmount > 0) {
            $paidAt = ReservationCashQuery::capPaymentTimestampToToday($startDate->copy()->subDays(2));
            ReservationPay::create([
                'reservation_id' => $reservation->id,
                'pay' => $payAmount,
                'type' => ReservationPay::TYPE_PAYMENT,
                'user_id' => $userId,
                'created_at' => $paidAt,
                'updated_at' => $paidAt,
            ]);
        }
    }

    private function createRoomPriceSnapshot(Room $room, int $reservationRoomId, Carbon $start, Carbon $end, int $rentType): void
    {
        $roomType = $room->roomType;
        if (! $roomType) {
            return;
        }

        $roomTypePlan = RoomtypePricingplan::where('roomtype_id', $room->room_type_id)
            ->whereHas('pricingplan', function ($q) use ($start, $end) {
                $q->where('StartDate', '<=', $end->toDateString())
                    ->where('EndDate', '>=', $start->toDateString());
            })->first();

        $data = ['reservation_room_id' => $reservationRoomId];

        if (Schema::hasColumn('room_prices', 'start_plan') && $roomTypePlan) {
            $pStart = Carbon::parse($roomTypePlan->pricingplan->StartDate);
            $pEnd = Carbon::parse($roomTypePlan->pricingplan->EndDate);
            $data['start_plan'] = $start->max($pStart)->toDateString();
            $data['end_plan'] = $end->min($pEnd)->toDateString();
        }

        if ($rentType === 0) {
            if ($roomTypePlan) {
                $data['pricing_plan_daily'] = $roomTypePlan->DailyPrice;
            }
            $data['min_price'] = $roomType->Min_daily_price;
            $data['max_price'] = $roomType->Max_daily_price;
        } else {
            if ($roomTypePlan) {
                $data['pricing_plan_monthly'] = $roomTypePlan->MonthlyPrice;
            }
            $data['min_month'] = $roomType->Min_monthly_price;
            $data['max_month'] = $roomType->Max_monthly_price;
        }

        RoomPrice::create($data);
    }

    private function roomHasOverlap(int $roomId, string $start, string $end): bool
    {
        return ReservationRoom::where('room_id', $roomId)
            ->whereHas('reservation', function ($q) use ($start, $end) {
                $q->where('start_date', '<', $end)
                    ->where('expire_date', '>', $start);
            })->exists();
    }

    private function ensureStayReason(): int
    {
        return Stay_reason::firstOrCreate(
            ['name_en' => 'Tourism'],
            ['name_ar' => 'سياحة', 'description' => 'Test stay reason']
        )->id;
    }

    private function ensureReservationSource(): int
    {
        return Reservation_source::firstOrCreate(
            ['name_en' => 'Walk-in'],
            ['name_ar' => 'مباشر', 'description' => 'Test source']
        )->id;
    }

    private function ensureClients()
    {
        if (Client::count() >= 4) {
            return Client::limit(8)->get();
        }

        $names = [
            ['Ahmed', 'Ali'],
            ['Sara', 'Hassan'],
            ['Omar', 'Khaled'],
            ['Layla', 'Mahmoud'],
            ['Youssef', 'Nasser'],
            ['Nour', 'Saleh'],
        ];

        foreach ($names as $i => [$first, $last]) {
            Client::firstOrCreate(
                ['IdNumber' => 'TEST'.(100000 + $i)],
                [
                    'first_name' => $first,
                    'last_name' => $last,
                    'email' => strtolower($first).'.test'.$i.'@hotel.test',
                    'international_code' => '+966',
                    'mobile' => '0500000'.str_pad((string) (100 + $i), 3, '0', STR_PAD_LEFT),
                    'IdType' => 'ID',
                    'birth_date' => '1990-01-15',
                    'gender' => 'MALE',
                    'guest_type' => 'VISITOR',
                    'nationality' => 'SA',
                ]
            );
        }

        return Client::limit(8)->get();
    }

    private function ensureRoomType(): RoomType
    {
        $this->call(HotelRoomTypesSeeder::class);

        return RoomType::query()->where('name_en', 'Deluxe')->firstOrFail();
    }

    private function ensurePeakMonthsForPricingTests(): void
    {
        PeakMonth::whereIn('month_name_en', ['June', 'July', 'August', 'December'])
            ->update(['check' => 1]);
    }

    private function ensurePricingPlan(RoomType $roomType): void
    {
        $y = $this->demoYear();
        $plan = Pricingplan::firstOrCreate(
            ['NameEn' => 'Summer Test Plan'],
            [
                'NameAr' => 'خطة صيف تجريبية',
                'StartDate' => "{$y}-07-01",
                'EndDate' => "{$y}-08-31",
                'ActiveType' => 1,
            ]
        );

        RoomtypePricingplan::firstOrCreate(
            [
                'roomtype_id' => $roomType->id,
                'pricingplan_id' => $plan->id,
            ],
            [
                'DailyPrice' => 150,
                'MonthlyPrice' => 3500,
            ]
        );
    }

    private function ensureRooms(RoomType $roomType)
    {
        $rooms = Room::with('roomType')
            ->where('active', 1)
            ->where('roomStatus', 1)
            ->where('room_type_id', $roomType->id)
            ->limit(10)
            ->get();

        if ($rooms->count() >= 8) {
            return $rooms;
        }

        [$buildingId, $floorId] = $this->ensureBuildingAndFloor();

        for ($n = 101; $n <= 108; $n++) {
            Room::firstOrCreate(
                ['number' => (string) $n, 'building_id' => $buildingId],
                [
                    'room_type_id' => $roomType->id,
                    'floor_id' => $floorId,
                    'suite_id' => null,
                    'active' => 1,
                    'roomStatus' => 1,
                ]
            );
        }

        return Room::with('roomType')
            ->where('active', 1)
            ->where('roomStatus', 1)
            ->limit(10)
            ->get();
    }

    private function ensureBuildingAndFloor(): array
    {
        $buildingId = DB::table('buildings')->value('id');
        if (! $buildingId) {
            $buildingId = DB::table('buildings')->insertGetId([
                'name' => 'Main Building',
                'number' => 'B1',
                'active' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $floorId = DB::table('floors')->where('building_id', $buildingId)->value('id');
        if (! $floorId) {
            $floorId = DB::table('floors')->insertGetId([
                'building_id' => $buildingId,
                'number' => '1',
                'active' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return [(int) $buildingId, (int) $floorId];
    }

    private function enableFridayPeak(): void
    {
        PeakDay::where('day_name_en', 'Friday')->update(['check' => 1]);
    }
}
