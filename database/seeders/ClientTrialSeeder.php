<?php

namespace Database\Seeders;

use App\Models\Client;
use App\Models\ClientNote;
use App\Models\Department;
use App\Models\Discount;
use App\Models\Guest_classification;
use App\Models\Hotel;
use App\Models\Job_title;
use App\Models\PeakDay;
use App\Models\PeakMonth;
use App\Models\Penaltie;
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
use App\Models\Tax;
use App\Models\User;
use App\Services\PricingEngine;
use App\Services\RevenueAccrualService;
use App\Support\ReservationCashQuery;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Small, realistic single-property dataset for a prospective client's trial
 * (one building, 4 floors, 40 rooms numbered 101-110/201-210/301-310/401-410).
 * Adapted from HotelDemoSeeder's proven approach, scaled down, and dated
 * relative to "today" (not a fixed historical window) so the trial always
 * shows recent/current/upcoming activity whenever it's actually run.
 *
 * Run: php artisan demo:setup --fresh --client-trial
 * Or:  php artisan db:seed --class=ClientTrialSeeder
 */
class ClientTrialSeeder extends Seeder
{
    private const ROOMS_PER_FLOOR = 10;

    private const FLOOR_COUNT = 4;

    private const TARGET_RESERVATIONS = 70;

    private const CLIENT_COUNT = 45;

    private PricingEngine $pricingEngine;

    private RevenueAccrualService $revenueAccrual;

    private int $userId;

    private array $stayReasonIds = [];

    private array $sourceIds = [];

    private $clients;

    private $rooms;

    public function run(): void
    {
        @ini_set('memory_limit', '512M');
        @set_time_limit(0);

        $this->pricingEngine = app(PricingEngine::class);
        $this->revenueAccrual = app(RevenueAccrualService::class);
        $this->userId = User::first()?->id ?? 1;

        $this->command?->info('Seeding client-trial property (1 building, 4 floors, 40 rooms)...');

        DB::transaction(function () {
            $this->seedReferenceData();
            $this->configurePeakPeriods();
            $this->seedProperty();
            $this->seedPricingPlans();
            $this->seedClients();
            $this->seedReservations();
            $this->seedClientNotes();
        });

        $this->printSummary();
    }

    private function seedReferenceData(): void
    {
        $frontOffice = Department::updateOrCreate(
            ['name_en' => 'Front Office'],
            ['name_ar' => 'الاستقبال', 'description' => 'Front desk operations']
        );
        Department::updateOrCreate(
            ['name_en' => 'Housekeeping'],
            ['name_ar' => 'التدبير المنزلي', 'description' => 'Housekeeping']
        );
        Department::updateOrCreate(
            ['name_en' => 'Finance'],
            ['name_ar' => 'المالية', 'description' => 'Finance department']
        );

        Job_title::updateOrCreate(
            ['name_en' => 'General Manager'],
            ['name_ar' => 'مدير عام', 'department_id' => $frontOffice->id]
        );
        Job_title::updateOrCreate(
            ['name_en' => 'Receptionist'],
            ['name_ar' => 'موظف استقبال', 'department_id' => $frontOffice->id]
        );

        $reasons = [
            ['name_en' => 'Tourism', 'name_ar' => 'سياحة'],
            ['name_en' => 'Business', 'name_ar' => 'عمل'],
            ['name_en' => 'Medical', 'name_ar' => 'علاج'],
            ['name_en' => 'Family visit', 'name_ar' => 'زيارة عائلية'],
        ];
        foreach ($reasons as $r) {
            $this->stayReasonIds[] = Stay_reason::updateOrCreate(
                ['name_en' => $r['name_en']],
                ['name_ar' => $r['name_ar'], 'description' => $r['name_en']]
            )->id;
        }

        $sources = [
            ['name_en' => 'Walk-in', 'name_ar' => 'مباشر'],
            ['name_en' => 'Website', 'name_ar' => 'الموقع'],
            ['name_en' => 'Booking.com', 'name_ar' => 'بوكينج'],
            ['name_en' => 'Corporate', 'name_ar' => 'شركات'],
            ['name_en' => 'Travel agent', 'name_ar' => 'وكالة سفر'],
        ];
        foreach ($sources as $s) {
            $this->sourceIds[] = Reservation_source::updateOrCreate(
                ['name_en' => $s['name_en']],
                ['name_ar' => $s['name_ar'], 'description' => $s['name_en']]
            )->id;
        }

        Discount::updateOrCreate(['name' => 'Staff 10%'], [
            'is_percentage' => true, 'percent' => 10, 'is_fixed' => false, 'fixed_amount' => 0, 'is_active' => true,
        ]);
        Discount::updateOrCreate(['name' => 'Loyalty 50 SAR'], [
            'is_percentage' => false, 'percent' => 0, 'is_fixed' => true, 'fixed_amount' => 50, 'is_active' => true,
        ]);

        Penaltie::updateOrCreate(
            ['name_en' => 'Late checkout'],
            ['name_ar' => 'تأخر مغادرة', 'type' => 1, 'value' => 100, 'description' => 'Fixed late checkout fee']
        );

        Tax::updateOrCreate(
            ['name_en' => 'VAT 15%'],
            ['name_ar' => 'ضريبة 15%', 'type' => 0, 'value' => 15, 'active' => 1]
        );

        Guest_classification::updateOrCreate(
            ['name_en' => 'VIP'],
            ['name_ar' => 'VIP', 'discount_id' => null, 'active' => 1]
        );
        Guest_classification::updateOrCreate(
            ['name_en' => 'Regular'],
            ['name_ar' => 'عادي', 'discount_id' => null, 'active' => 1]
        );
    }

    private function configurePeakPeriods(): void
    {
        PeakDay::whereIn('day_name_en', ['Thursday', 'Friday'])->update(['check' => 1]);
        PeakMonth::whereIn('month_name_en', ['June', 'July', 'August', 'December'])->update(['check' => 1]);
    }

    /**
     * Room type per floor — a believable hotel layout (lower floors = simpler
     * rooms, top floor = suites), covering every type the client asked for.
     *
     * @return array<string, array{from: int, to: int}>
     */
    private function roomTypeRangesByFloor(): array
    {
        return [
            1 => [
                'Single Room' => ['from' => 101, 'to' => 105],
                'Double Room' => ['from' => 106, 'to' => 110],
            ],
            2 => [
                'Standard Room' => ['from' => 201, 'to' => 206],
                'Superior Room' => ['from' => 207, 'to' => 210],
            ],
            3 => [
                'Superior Room' => ['from' => 301, 'to' => 302],
                'Deluxe Room' => ['from' => 303, 'to' => 310],
            ],
            4 => [
                'Junior Suite' => ['from' => 401, 'to' => 406],
                'Executive Suite' => ['from' => 407, 'to' => 410],
            ],
        ];
    }

    private function seedProperty(): void
    {
        $types = [
            ['name_en' => 'Single Room', 'name_ar' => 'غرفة مفردة', 'min_d' => 150, 'max_d' => 220, 'min_m' => 3500, 'max_m' => 5000, 'capacity' => 1],
            ['name_en' => 'Double Room', 'name_ar' => 'غرفة مزدوجة', 'min_d' => 200, 'max_d' => 300, 'min_m' => 4800, 'max_m' => 6500, 'capacity' => 2],
            ['name_en' => 'Standard Room', 'name_ar' => 'غرفة ستاندرد', 'min_d' => 180, 'max_d' => 280, 'min_m' => 4200, 'max_m' => 6500, 'capacity' => 2],
            ['name_en' => 'Superior Room', 'name_ar' => 'غرفة سوبيريور', 'min_d' => 240, 'max_d' => 360, 'min_m' => 5600, 'max_m' => 8200, 'capacity' => 2],
            ['name_en' => 'Deluxe Room', 'name_ar' => 'غرفة ديلوكس', 'min_d' => 320, 'max_d' => 480, 'min_m' => 7500, 'max_m' => 11500, 'capacity' => 3],
            ['name_en' => 'Junior Suite', 'name_ar' => 'جناح جونيور', 'min_d' => 450, 'max_d' => 650, 'min_m' => 10000, 'max_m' => 15000, 'capacity' => 4],
            ['name_en' => 'Executive Suite', 'name_ar' => 'جناح تنفيذي', 'min_d' => 650, 'max_d' => 950, 'min_m' => 14000, 'max_m' => 20000, 'capacity' => 4],
        ];

        $roomTypeIdByName = [];
        foreach ($types as $t) {
            $roomTypeIdByName[$t['name_en']] = RoomType::updateOrCreate(
                ['name_en' => $t['name_en']],
                [
                    'name_ar' => $t['name_ar'],
                    'description' => $t['name_en'].' — '.$t['name_ar'],
                    'Min_daily_price' => $t['min_d'],
                    'Max_daily_price' => $t['max_d'],
                    'Min_monthly_price' => $t['min_m'],
                    'Max_monthly_price' => $t['max_m'],
                    'active_type' => 1,
                ]
            )->id;
        }

        $hotelId = Hotel::query()->orderBy('id')->value('id') ?? 1;

        $buildingId = DB::table('buildings')->insertGetId([
            'hotel_id' => $hotelId,
            'name' => 'Main Building',
            'number' => 'A',
            'active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $roomCollection = collect();
        $floorRanges = $this->roomTypeRangesByFloor();
        $capacityByName = collect($types)->pluck('capacity', 'name_en');

        for ($floor = 1; $floor <= self::FLOOR_COUNT; $floor++) {
            $floorId = DB::table('floors')->insertGetId([
                'building_id' => $buildingId,
                'number' => (string) $floor,
                'active' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            for ($i = 1; $i <= self::ROOMS_PER_FLOOR; $i++) {
                $roomNumber = (string) ($floor * 100 + $i);
                $typeName = $this->typeNameForRoomNumber((int) $roomNumber, $floorRanges[$floor]);

                $roomId = DB::table('rooms')->insertGetId([
                    'building_id' => $buildingId,
                    'floor_id' => $floorId,
                    'suite_id' => null,
                    'number' => $roomNumber,
                    'room_type_id' => $roomTypeIdByName[$typeName],
                    'capacity' => $capacityByName[$typeName],
                    'roomStatus' => 1,
                    'active' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $roomCollection->push(Room::with('roomType')->find($roomId));
            }
        }

        $this->rooms = $roomCollection;
        $this->command?->info('  Property: 1 building, '.self::FLOOR_COUNT.' floors, '.$this->rooms->count().' rooms, '.count($types).' room types.');
    }

    /** @param array<string, array{from:int, to:int}> $ranges */
    private function typeNameForRoomNumber(int $roomNumber, array $ranges): string
    {
        foreach ($ranges as $typeName => $range) {
            if ($roomNumber >= $range['from'] && $roomNumber <= $range['to']) {
                return $typeName;
            }
        }

        // Should never happen given the fixed ranges above cover every room.
        return array_key_first($ranges);
    }

    private function seedPricingPlans(): void
    {
        $roomTypes = RoomType::all()->keyBy('name_en');

        $plan = Pricingplan::updateOrCreate(
            ['NameEn' => 'Trial Standard Rate'],
            [
                'NameAr' => 'التسعيرة التجريبية',
                'StartDate' => Carbon::now()->subMonths(6)->toDateString(),
                'EndDate' => Carbon::now()->addMonths(6)->toDateString(),
                'ActiveType' => 1,
            ]
        );

        foreach ($roomTypes as $name => $roomType) {
            RoomtypePricingplan::updateOrCreate(
                ['roomtype_id' => $roomType->id, 'pricingplan_id' => $plan->id],
                [
                    // Mid-point of the room type's own min/max band.
                    'DailyPrice' => round(($roomType->Min_daily_price + $roomType->Max_daily_price) / 2),
                    'MonthlyPrice' => round(($roomType->Min_monthly_price + $roomType->Max_monthly_price) / 2),
                ]
            );
        }
    }

    private function seedClients(): void
    {
        $firstNames = ['Ahmed', 'Sara', 'Omar', 'Layla', 'Khalid', 'Nour', 'Faisal', 'Huda', 'Youssef', 'Maha', 'Ali', 'Reem', 'Hassan', 'Dina', 'Tariq', 'Lina'];
        $lastNames = ['Al-Ahmad', 'Al-Hassan', 'Khoury', 'Nasser', 'Khan', 'Smith', 'Al-Masri', 'Johnson', 'Al-Hariri', 'Brown'];

        for ($i = 0; $i < self::CLIENT_COUNT; $i++) {
            $first = $firstNames[$i % count($firstNames)];
            $last = $lastNames[intdiv($i, count($firstNames)) % count($lastNames)];

            Client::updateOrCreate(
                ['IdNumber' => 'TRIAL'.str_pad((string) ($i + 1), 6, '0', STR_PAD_LEFT)],
                [
                    'first_name' => $first,
                    'last_name' => $last,
                    'email' => strtolower($first.'.'.$last.$i.'@trial.hotel'),
                    'international_code' => '+963',
                    'mobile' => '09'.str_pad((string) (10000000 + $i), 8, '0', STR_PAD_LEFT),
                    'IdType' => $i % 5 === 0 ? 'PASSPORT' : 'ID',
                    'birth_date' => Carbon::now()->subYears(rand(22, 65))->format('Y-m-d'),
                    'gender' => $i % 2 === 0 ? 'MALE' : 'FEMALE',
                    'guest_type' => ['CITIZEN', 'RESIDENT', 'VISITOR', 'GULF CITIZEN'][$i % 4],
                    'nationality' => ['SY', 'SA', 'AE', 'EG', 'JO'][$i % 5],
                ]
            );
        }

        $this->clients = Client::limit(self::CLIENT_COUNT)->get();
        $this->command?->info('  Clients: '.$this->clients->count());
    }

    private function seedReservations(): void
    {
        // Past two months through next month — always shows checked-out
        // history, in-house occupancy right now, and upcoming bookings,
        // regardless of which day this seeder actually runs on.
        $start = Carbon::today()->subMonths(2);
        $end = Carbon::today()->addMonth();
        $today = Carbon::today();
        $created = 0;
        $attempts = 0;
        $maxAttempts = self::TARGET_RESERVATIONS * 10;

        while ($created < self::TARGET_RESERVATIONS && $attempts < $maxAttempts) {
            $attempts++;

            $checkIn = $start->copy()->addDays(random_int(0, max(1, $start->diffInDays($end) - 2)));
            $nights = random_int(1, min(10, max(1, $checkIn->diffInDays($end))));
            $checkOut = $checkIn->copy()->addDays($nights);

            if ($checkOut->gt($end)) {
                continue;
            }

            $room = $this->rooms[random_int(0, $this->rooms->count() - 1)];
            if ($this->roomHasOverlap($room->id, $checkIn->toDateString(), $checkOut->toDateString())) {
                continue;
            }

            $client = $this->clients[random_int(0, $this->clients->count() - 1)];
            $discount = [0, 0, 0, 50, 100][random_int(0, 4)];
            $extras = random_int(0, 10) < 2 ? random_int(50, 300) : 0;
            $penalties = random_int(0, 20) === 0 ? random_int(50, 150) : 0;
            $payMode = ['full', 'full', 'full', 'partial', 'none'][random_int(0, 4)];

            $isPast = $checkOut->lt($today);
            $isCurrent = $checkIn->lte($today) && $checkOut->gte($today);
            $status = $payMode === 'none' && ! $isPast ? 2 : 1;

            $this->createReservation(
                $room,
                $client->id,
                $checkIn,
                $checkOut,
                $nights,
                $status,
                $discount,
                $extras,
                $penalties,
                $payMode,
                $isCurrent ? 1 : ($isPast ? 0 : random_int(0, 1)),
                random_int(0, count($this->stayReasonIds) - 1),
                random_int(0, count($this->sourceIds) - 1)
            );

            $created++;
        }

        $this->command?->info('  Reservations created: '.$created);
    }

    private function createReservation(
        Room $room,
        int $clientId,
        Carbon $startDate,
        Carbon $endDate,
        int $nights,
        int $status,
        float $discount,
        float $extras,
        float $penalties,
        string $payMode,
        int $logedin,
        int $reasonIndex,
        int $sourceIndex
    ): void {
        $lines = $this->pricingEngine->buildDailyBreakdown(
            $room,
            $startDate->toDateString(),
            $endDate->toDateString(),
            0,
            0
        );

        $basePrice = round(array_sum(array_column($lines, 'base_amount')), 2);
        $discount = min($discount, max(0, $basePrice - 1));
        $subtotal = $basePrice - $discount + $extras + $penalties;
        $taxes = round($subtotal * 0.15, 2, PHP_ROUND_HALF_UP);
        $total = round($subtotal + $taxes, 2);

        $payAmount = match ($payMode) {
            'full' => $total,
            'partial' => round($total * random_int(30, 70) / 100, 2),
            default => 0,
        };

        $reservation = Reservation::create([
            'client_id' => $clientId,
            'start_date' => $startDate->toDateString(),
            'nights' => $nights,
            'expire_date' => $endDate->toDateString(),
            'reservation_type' => 0,
            'reservation_status' => $status,
            'stay_reason_id' => $this->stayReasonIds[$reasonIndex],
            'reservation_source_id' => $this->sourceIds[$sourceIndex],
            'rent_type' => 0,
            'base_price' => $basePrice,
            'discount' => $discount,
            'extras' => $extras,
            'penalties' => $penalties,
            'subtotal' => $subtotal,
            'taxes' => $taxes,
            'total' => $total,
            'logedin' => $logedin,
            'login_time' => $startDate->toDateString(),
            'user_id' => $this->userId,
            'created_at' => $startDate->copy()->subDays(random_int(1, 10)),
            'updated_at' => $startDate->copy()->subDays(random_int(0, 3)),
        ]);

        $resRoom = ReservationRoom::create([
            'reservation_id' => $reservation->id,
            'room_id' => $room->id,
            'suite_id' => null,
            'price' => $total,
        ]);

        $this->revenueAccrual->persistDailyCharges(
            $reservation->id,
            $resRoom->id,
            $room->id,
            0,
            $lines
        );

        $this->createRoomPriceSnapshot($room, $resRoom->id, $startDate, $endDate);

        if ($payAmount > 0) {
            $paidAt = ReservationCashQuery::capPaymentTimestampToToday(
                $startDate->copy()->subDays(random_int(0, 5))
            );
            ReservationPay::create([
                'reservation_id' => $reservation->id,
                'pay' => $payAmount,
                'type' => ReservationPay::TYPE_PAYMENT,
                'user_id' => $this->userId,
                'created_at' => $paidAt,
                'updated_at' => $paidAt,
            ]);
        }
    }

    private function createRoomPriceSnapshot(Room $room, int $reservationRoomId, Carbon $start, Carbon $end): void
    {
        $roomType = $room->roomType;
        if (! $roomType) {
            return;
        }

        $roomTypePlan = RoomtypePricingplan::where('roomtype_id', $room->room_type_id)
            ->whereHas('pricingplan', fn ($q) => $q->where('StartDate', '<=', $end)->where('EndDate', '>=', $start))
            ->first();

        $data = ['reservation_room_id' => $reservationRoomId];

        if (Schema::hasColumn('room_prices', 'start_plan') && $roomTypePlan) {
            $pStart = Carbon::parse($roomTypePlan->pricingplan->StartDate);
            $pEnd = Carbon::parse($roomTypePlan->pricingplan->EndDate);
            $data['start_plan'] = $start->max($pStart)->toDateString();
            $data['end_plan'] = $end->min($pEnd)->toDateString();
        }

        if ($roomTypePlan) {
            $data['pricing_plan_daily'] = $roomTypePlan->DailyPrice;
        }
        $data['min_price'] = $roomType->Min_daily_price;
        $data['max_price'] = $roomType->Max_daily_price;

        RoomPrice::create($data);
    }

    private function roomHasOverlap(int $roomId, string $start, string $end): bool
    {
        return ReservationRoom::where('room_id', $roomId)
            ->whereHas('reservation', fn ($q) => $q->where('start_date', '<', $end)->where('expire_date', '>', $start))
            ->exists();
    }

    private function seedClientNotes(): void
    {
        if (! Schema::connection('mysql2')->hasTable('client_notes')) {
            return;
        }

        $sample = $this->clients->random(min(15, $this->clients->count()));
        foreach ($sample as $client) {
            ClientNote::create([
                'client_id' => $client->id,
                'title' => 'Guest preference',
                'description' => 'Trial note for '.$client->first_name.' — prefers high floor, late checkout once.',
            ]);
        }
    }

    private function printSummary(): void
    {
        $this->command?->newLine();
        $this->command?->table(
            ['Metric', 'Count'],
            [
                ['Buildings', DB::table('buildings')->count()],
                ['Floors', DB::table('floors')->count()],
                ['Rooms', Room::count()],
                ['Room types', RoomType::count()],
                ['Clients', Client::count()],
                ['Reservations', Reservation::count()],
                ['Daily charge lines', ReservationDailyCharge::count()],
                ['Payments', ReservationPay::where('type', ReservationPay::TYPE_PAYMENT)->count()],
            ]
        );
    }
}
