<?php

namespace Database\Seeders;

/**
 * Six lightweight test reservations: 3 in July 2026 + 3 in August 2026.
 *
 * Run: php artisan db:seed --class=MinimalJulAugReservationSeeder
 */
class MinimalJulAugReservationSeeder extends ReservationTestDataSeeder
{
    protected bool $skipClearOnRun = true;

    protected function scenarios(): array
    {
        return [
            ['start' => '2026-07-05', 'end' => '2026-07-08', 'rent_type' => 0, 'discount' => 0, 'pay' => 'full', 'status' => 1],
            ['start' => '2026-07-12', 'end' => '2026-07-15', 'rent_type' => 0, 'discount' => 0, 'pay' => 'partial', 'status' => 1],
            ['start' => '2026-07-20', 'end' => '2026-07-25', 'rent_type' => 0, 'discount' => 0, 'pay' => 'none', 'status' => 2],
            ['start' => '2026-08-02', 'end' => '2026-08-06', 'rent_type' => 0, 'discount' => 50, 'pay' => 'full', 'status' => 1],
            ['start' => '2026-08-10', 'end' => '2026-08-14', 'rent_type' => 0, 'discount' => 0, 'pay' => 'full', 'status' => 1],
            ['start' => '2026-08-18', 'end' => '2026-08-22', 'rent_type' => 0, 'discount' => 0, 'pay' => 'partial', 'status' => 1],
        ];
    }

    public function run(): void
    {
        $this->command?->info('Seeding minimal Jul/Aug 2026 reservations (6 total)...');

        parent::run();

        $this->command?->table(
            ['Period', 'Filter suggestion'],
            [
                ['July 2026', 'Reports → 2026-07-01 to 2026-07-31 (3 reservations)'],
                ['August 2026', 'Reports → 2026-08-01 to 2026-08-31 (3 reservations)'],
            ]
        );
    }
}
