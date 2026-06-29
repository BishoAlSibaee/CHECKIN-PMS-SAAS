<?php

namespace Database\Seeders;

use App\Models\Client;
use App\Models\ClientNote;
use App\Models\Department;
use App\Models\Discount;
use App\Models\Guest_classification;
use App\Models\Job_title;
use App\Models\PeakDay;
use App\Models\PeakMonth;
use App\Models\Penaltie;
use App\Models\Pricingplan;
use App\Models\Reservation;
use App\Models\ReservationPay;
use App\Support\ReservationCashQuery;
use App\Models\ReservationRoom;
use App\Models\Reservation_source;
use App\Models\ReservationDailyCharge;
use App\Models\Room;
use App\Models\RoomPrice;
use App\Models\RoomtypePricingplan;
use App\Models\RoomType;
use App\Models\Stay_reason;
use App\Models\Tax;
use App\Models\User;
use App\Services\PricingEngine;
use App\Services\RevenueAccrualService;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Large demo dataset: 2 buildings × 15 floors, ~2 years of reservations.
 *
 * Run: php artisan migrate:fresh --seed
 */
class HotelDemoSeeder extends Seeder
{
    private const DEMO_START = '2024-06-01';
    private const DEMO_END = '2026-12-31';
    private const BUILDING_COUNT = 2;
    private const FLOORS_PER_BUILDING = 15;
    private const ROOMS_PER_FLOOR = 6;
    private const TARGET_RESERVATIONS = 650;
    private const CLIENT_COUNT = 180;

    private PricingEngine $pricingEngine;
    private RevenueAccrualService $revenueAccrual;
    private int $userId;
    private array $stayReasonIds = [];
    private array $sourceIds = [];
    private array $roomTypeIds = [];
    private $clients;
    private $rooms;

    public function run(): void
    {
        @ini_set('memory_limit', '1024M');
        @set_time_limit(0);

        $this->pricingEngine = app(PricingEngine::class);
        $this->revenueAccrual = app(RevenueAccrualService::class);
        $this->userId = User::first()?->id ?? 1;

        $this->command?->info('Seeding hotel demo data (2 buildings, 15 floors, 2 years)...');

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
            ['name_en' => 'Booking.com', 'name_ar' => 'بوoking'],
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
        Discount::updateOrCreate(['name' => 'Corporate 50 SAR'], [
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

    private function seedProperty(): void
    {
        $types = [
            ['name_en' => 'Standard', 'name_ar' => 'ستاندرد', 'min_d' => 180, 'max_d' => 280, 'min_m' => 4200, 'max_m' => 6500],
            ['name_en' => 'Superior', 'name_ar' => 'ممتاز', 'min_d' => 240, 'max_d' => 360, 'min_m' => 5600, 'max_m' => 8200],
            ['name_en' => 'Deluxe', 'name_ar' => 'ديلوكس', 'min_d' => 320, 'max_d' => 480, 'min_m' => 7500, 'max_m' => 11500],
            ['name_en' => 'Family', 'name_ar' => 'عائلي', 'min_d' => 380, 'max_d' => 520, 'min_m' => 8800, 'max_m' => 12500],
            ['name_en' => 'Suite', 'name_ar' => 'جناح', 'min_d' => 550, 'max_d' => 850, 'min_m' => 13000, 'max_m' => 20000],
        ];

        foreach ($types as $t) {
            $this->roomTypeIds[] = RoomType::updateOrCreate(
                ['name_en' => $t['name_en']],
                [
                    'name_ar' => $t['name_ar'],
                    'description' => match ($t['name_en']) {
                        'Standard' => 'Comfortable room with essential amenities for business or short stays.',
                        'Superior' => 'Spacious room with upgraded bedding and city or garden view.',
                        'Deluxe' => 'Premium room with lounge area, minibar, and enhanced in-room services.',
                        'Family' => 'Large room for families with extra beds and connecting options.',
                        'Suite' => 'Executive suite with separate living area and VIP amenities.',
                        default => $t['name_en'] . ' room',
                    },
                    'Min_daily_price' => $t['min_d'],
                    'Max_daily_price' => $t['max_d'],
                    'Min_monthly_price' => $t['min_m'],
                    'Max_monthly_price' => $t['max_m'],
                    'active_type' => 1,
                ]
            )->id;
        }

        $buildingNames = [
            ['name' => 'North Tower', 'number' => 'A', 'ar' => 'البرج الشمالي'],
            ['name' => 'South Tower', 'number' => 'B', 'ar' => 'البرج الجنوبي'],
        ];

        $roomCollection = collect();

        foreach ($buildingNames as $bIndex => $bMeta) {
            $buildingId = DB::table('buildings')->insertGetId([
                'name' => $bMeta['name'],
                'number' => $bMeta['number'],
                'active' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            for ($floor = 1; $floor <= self::FLOORS_PER_BUILDING; $floor++) {
                $floorId = DB::table('floors')->insertGetId([
                    'building_id' => $buildingId,
                    'number' => (string) $floor,
                    'active' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                for ($r = 1; $r <= self::ROOMS_PER_FLOOR; $r++) {
                    $roomNumber = sprintf('%s-%d%02d', $bMeta['number'], $floor, $r);
                    $typeIndex = ($bIndex + $floor + $r) % count($this->roomTypeIds);

                    $roomId = DB::table('rooms')->insertGetId([
                        'building_id' => $buildingId,
                        'floor_id' => $floorId,
                        'suite_id' => null,
                        'number' => $roomNumber,
                        'room_type_id' => $this->roomTypeIds[$typeIndex],
                        'capacity' => $typeIndex >= 2 ? 4 : 2,
                        'roomStatus' => 1,
                        'active' => 1,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                    $roomCollection->push(Room::with('roomType')->find($roomId));
                }
            }
        }

        $this->rooms = $roomCollection;
        $this->command?->info('  Property: ' . self::BUILDING_COUNT . ' buildings, '
            . (self::BUILDING_COUNT * self::FLOORS_PER_BUILDING) . ' floors, '
            . $this->rooms->count() . ' rooms.');

        $this->call(DemoSuitesSeeder::class);
    }

    private function seedPricingPlans(): void
    {
        $plans = [
            ['NameEn' => 'Standard Rate 2024-2026', 'NameAr' => 'تسعيرة أغسطس–ديسمبر 2026', 'StartDate' => '2026-08-05', 'EndDate' => '2026-12-31', 'daily' => [220, 300, 380, 420, 620], 'monthly' => [5200, 6800, 9000, 10200, 15000]],
            ['NameEn' => 'Summer Peak 2025', 'NameAr' => 'ذروة صيف 2025', 'StartDate' => '2025-06-01', 'EndDate' => '2025-08-31', 'daily' => [260, 340, 430, 480, 700], 'monthly' => [6000, 7800, 10200, 11800, 17000]],
        ];

        foreach ($plans as $p) {
            $plan = Pricingplan::updateOrCreate(
                ['NameEn' => $p['NameEn']],
                ['NameAr' => $p['NameAr'], 'StartDate' => $p['StartDate'], 'EndDate' => $p['EndDate'], 'ActiveType' => 1]
            );

            foreach ($this->roomTypeIds as $i => $typeId) {
                RoomtypePricingplan::updateOrCreate(
                    ['roomtype_id' => $typeId, 'pricingplan_id' => $plan->id],
                    ['DailyPrice' => $p['daily'][$i], 'MonthlyPrice' => $p['monthly'][$i]]
                );
            }
        }
    }

    private function seedClients(): void
    {
        $firstNames = ['Ahmed', 'Sara', 'Omar', 'Layla', 'Khalid', 'Nour', 'Faisal', 'Huda', 'Youssef', 'Maha', 'Ali', 'Reem', 'Hassan', 'Dina', 'Tariq', 'Lina'];
        $lastNames = ['Al-Otaibi', 'Al-Harbi', 'Al-Ghamdi', 'Al-Zahrani', 'Khan', 'Smith', 'Al-Farsi', 'Johnson', 'Al-Mutairi', 'Brown'];

        for ($i = 0; $i < self::CLIENT_COUNT; $i++) {
            $first = $firstNames[$i % count($firstNames)];
            $last = $lastNames[intdiv($i, count($firstNames)) % count($lastNames)];

            Client::updateOrCreate(
                ['IdNumber' => 'DEMO' . str_pad((string) ($i + 1), 6, '0', STR_PAD_LEFT)],
                [
                    'first_name' => $first,
                    'last_name' => $last,
                    'email' => strtolower($first . '.' . $last . $i . '@demo.hotel'),
                    'international_code' => '+966',
                    'mobile' => '05' . str_pad((string) (10000000 + $i), 8, '0', STR_PAD_LEFT),
                    'IdType' => $i % 5 === 0 ? 'PASSPORT' : 'ID',
                    'birth_date' => Carbon::now()->subYears(rand(22, 65))->format('Y-m-d'),
                    'gender' => $i % 2 === 0 ? 'MALE' : 'FEMALE',
                    'guest_type' => ['CITIZEN', 'RESIDENT', 'VISITOR', 'GULF CITIZEN'][$i % 4],
                    'nationality' => ['SA', 'AE', 'EG', 'JO', 'US'][$i % 5],
                ]
            );
        }

        $this->clients = Client::limit(self::CLIENT_COUNT)->get();
        $this->command?->info('  Clients: ' . $this->clients->count());
    }

    private function seedReservations(): void
    {
        $start = Carbon::parse(self::DEMO_START);
        $end = Carbon::parse(self::DEMO_END);
        $today = Carbon::today();
        $created = 0;
        $attempts = 0;
        $maxAttempts = self::TARGET_RESERVATIONS * 8;

        while ($created < self::TARGET_RESERVATIONS && $attempts < $maxAttempts) {
            $attempts++;

            $checkIn = $start->copy()->addDays(random_int(0, max(1, $start->diffInDays($end) - 3)));
            $nights = random_int(1, min(14, $checkIn->diffInDays($end)));
            $checkOut = $checkIn->copy()->addDays($nights);

            if ($checkOut->gt($end)) {
                continue;
            }

            $room = $this->rooms[random_int(0, $this->rooms->count() - 1)];
            if ($this->roomHasOverlap($room->id, $checkIn->toDateString(), $checkOut->toDateString())) {
                continue;
            }

            $client = $this->clients[random_int(0, $this->clients->count() - 1)];
            $discount = [0, 0, 0, 50, 100, 150, 200][random_int(0, 6)];
            $extras = random_int(0, 10) < 2 ? random_int(50, 300) : 0;
            $penalties = random_int(0, 20) === 0 ? random_int(50, 150) : 0;
            $payMode = ['full', 'full', 'full', 'partial', 'none'][random_int(0, 4)];

            $isPast = $checkOut->lt($today);
            $isCurrent = $checkIn->lte($today) && $checkOut->gte($today);
            $status = $payMode === 'none' && !$isPast ? 2 : 1;

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

            if ($created % 100 === 0) {
                $this->command?->info("  Reservations: {$created}/" . self::TARGET_RESERVATIONS);
            }
        }

        $this->command?->info('  Reservations created: ' . $created);
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
        $rentType = random_int(0, 15) === 0 ? 1 : 0;
        $lines = $this->pricingEngine->buildDailyBreakdown(
            $room,
            $startDate->toDateString(),
            $endDate->toDateString(),
            $rentType,
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
            'rent_type' => $rentType,
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
            'created_at' => $startDate->copy()->subDays(random_int(1, 14)),
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
            $rentType,
            $lines
        );

        $this->createRoomPriceSnapshot($room, $resRoom->id, $startDate, $endDate, $rentType);

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

            if ($payMode === 'full' && random_int(0, 40) === 0 && $endDate->lt(Carbon::today())) {
                ReservationPay::create([
                    'reservation_id' => $reservation->id,
                    'pay' => round($payAmount * random_int(5, 20) / 100, 2),
                    'type' => ReservationPay::TYPE_REFUND,
                    'user_id' => $this->userId,
                    'created_at' => $endDate->copy()->addDays(random_int(1, 5)),
                    'updated_at' => $endDate->copy()->addDays(random_int(1, 5)),
                ]);
            }
        }
    }

    private function createRoomPriceSnapshot(Room $room, int $reservationRoomId, Carbon $start, Carbon $end, int $rentType): void
    {
        $roomType = $room->roomType;
        if (!$roomType) {
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
            ->whereHas('reservation', fn ($q) => $q->where('start_date', '<', $end)->where('expire_date', '>', $start))
            ->exists();
    }

    private function seedClientNotes(): void
    {
        if (! Schema::connection('mysql2')->hasTable('client_notes')) {
            return;
        }

        $sample = $this->clients->random(min(40, $this->clients->count()));
        foreach ($sample as $client) {
            ClientNote::create([
                'client_id' => $client->id,
                'title' => 'Guest preference',
                'description' => 'Demo note for ' . $client->first_name . ' — prefers high floor, late checkout once.',
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
                ['Refunds', ReservationPay::where('type', ReservationPay::TYPE_REFUND)->count()],
            ]
        );
        $this->command?->info('Demo period: ' . self::DEMO_START . ' → ' . self::DEMO_END);
    }
}
