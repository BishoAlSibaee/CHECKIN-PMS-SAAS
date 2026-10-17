<?php

namespace Database\Seeders;

/**
 * Three varied July 2026 reservations (full / partial / unpaid).
 *
 * Run: php artisan db:seed --class=JulyThreeReservationsSeeder --force
 */
class JulyThreeReservationsSeeder extends ReservationTestDataSeeder
{
    protected bool $skipClearOnRun = true;

    protected function scenarios(): array
    {
        return [
            // Confirmed, paid in full, short stay early July
            ['start' => '2026-07-05', 'end' => '2026-07-08', 'rent_type' => 0, 'discount' => 0, 'pay' => 'full', 'status' => 1],
            // Confirmed, partial payment mid-July
            ['start' => '2026-07-12', 'end' => '2026-07-15', 'rent_type' => 0, 'discount' => 50, 'pay' => 'partial', 'status' => 1],
            // Pending payment, unpaid, longer stay late July
            ['start' => '2026-07-20', 'end' => '2026-07-25', 'rent_type' => 0, 'discount' => 0, 'pay' => 'none', 'status' => 2],
        ];
    }

    public function run(): void
    {
        $this->command?->info('Seeding 3 diverse July 2026 reservations...');
        parent::run();
    }
}
