<?php

namespace Database\Seeders;

use App\Models\RefundPolicy;
use Illuminate\Database\Seeder;

class RefundPolicySeeder extends Seeder
{
    public function run(): void
    {
        if (!RefundPolicy::query()->where('timing', 'before_start')->exists()) {
            $policies = [
                [
                    'name' => 'Daily — 30+ days before check-in (100%)',
                    'rent_type' => 0,
                    'timing' => 'before_start',
                    'days_threshold' => 30,
                    'days_before_checkin' => 30,
                    'refund_percent' => 100,
                    'refund_basis' => 'remaining_nights',
                    'payment_status' => 2,
                    'during_stay' => 0,
                ],
                [
                    'name' => 'Daily — 7+ days before check-in (50%)',
                    'rent_type' => 0,
                    'timing' => 'before_start',
                    'days_threshold' => 7,
                    'days_before_checkin' => 7,
                    'refund_percent' => 50,
                    'refund_basis' => 'remaining_nights',
                    'payment_status' => 2,
                    'during_stay' => 0,
                ],
                [
                    'name' => 'Daily — partial payment, 14+ days before (80%)',
                    'rent_type' => 0,
                    'timing' => 'before_start',
                    'days_threshold' => 14,
                    'days_before_checkin' => 14,
                    'refund_percent' => 80,
                    'refund_basis' => 'paid_net',
                    'payment_status' => 1,
                    'during_stay' => 0,
                ],
                [
                    'name' => 'Monthly — 14+ days before check-in (80%)',
                    'rent_type' => 1,
                    'timing' => 'before_start',
                    'days_threshold' => 14,
                    'days_before_checkin' => 14,
                    'refund_percent' => 80,
                    'refund_basis' => 'paid_net',
                    'payment_status' => 2,
                    'during_stay' => 0,
                ],
                [
                    'name' => 'During stay — 50% of stay elapsed (40%)',
                    'rent_type' => null,
                    'timing' => 'after_start',
                    'threshold_mode' => 'percent_of_stay',
                    'threshold_percent' => 50,
                    'days_threshold' => 0,
                    'days_before_checkin' => 0,
                    'refund_percent' => 40,
                    'refund_basis' => 'paid_net',
                    'payment_status' => 2,
                    'during_stay' => 1,
                ],
            ];

            foreach ($policies as $policy) {
                RefundPolicy::updateOrCreate(['name' => $policy['name']], $policy);
            }

            $this->command?->info('Refund policies seeded: ' . count($policies));
        }

        $this->ensureDuringStayDefaults();
    }

    private function ensureDuringStayDefaults(): void
    {
        RefundPolicy::updateOrCreate(
            ['name' => 'During stay — full payment, from day 1 (50%)'],
            [
                'rent_type' => null,
                'timing' => 'after_start',
                'days_threshold' => 0,
                'days_before_checkin' => 0,
                'refund_percent' => 50,
                'refund_basis' => 'paid_net',
                'payment_status' => 2,
                'during_stay' => 1,
            ]
        );
    }
}
