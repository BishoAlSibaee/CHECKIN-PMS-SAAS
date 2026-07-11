<?php

namespace Database\Seeders;

use App\Models\Guest_classification;
use App\Models\Reservation_source;
use App\Models\Stay_reason;
use App\Models\Tax;
use Illuminate\Database\Seeder;

/**
 * Minimal lookup data required for a fresh install (no property, rooms, or reservations).
 *
 * Run: php artisan db:seed --class=EssentialReferenceDataSeeder
 */
class EssentialReferenceDataSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->stayReasons() as $reason) {
            Stay_reason::updateOrCreate(
                ['name_en' => $reason['name_en']],
                ['name_ar' => $reason['name_ar'], 'description' => $reason['name_en']]
            );
        }

        foreach ($this->reservationSources() as $source) {
            Reservation_source::updateOrCreate(
                ['name_en' => $source['name_en']],
                ['name_ar' => $source['name_ar'], 'description' => $source['name_en']]
            );
        }

        foreach ($this->guestClassifications() as $classification) {
            Guest_classification::updateOrCreate(
                ['name_en' => $classification['name_en']],
                [
                    'name_ar' => $classification['name_ar'],
                    'discount_id' => null,
                    'active' => 1,
                ]
            );
        }

        Tax::updateOrCreate(
            ['name_en' => 'VAT 15%'],
            ['name_ar' => 'ضريبة 15%', 'type' => 0, 'value' => 15, 'active' => 1]
        );

        $this->command?->info('Essential reference data ready (stay reasons, sources, guest classifications, VAT).');
    }

    /** @return list<array{name_en: string, name_ar: string}> */
    private function stayReasons(): array
    {
        return [
            ['name_en' => 'Tourism', 'name_ar' => 'سياحة'],
            ['name_en' => 'Business', 'name_ar' => 'عمل'],
            ['name_en' => 'Medical', 'name_ar' => 'علاج'],
            ['name_en' => 'Family visit', 'name_ar' => 'زيارة عائلية'],
        ];
    }

    /** @return list<array{name_en: string, name_ar: string}> */
    private function reservationSources(): array
    {
        return [
            ['name_en' => 'Walk-in', 'name_ar' => 'مباشر'],
            ['name_en' => 'Website', 'name_ar' => 'الموقع'],
            ['name_en' => 'Phone', 'name_ar' => 'هاتف'],
            ['name_en' => 'Corporate', 'name_ar' => 'شركات'],
            ['name_en' => 'Travel agent', 'name_ar' => 'وكالة سفر'],
        ];
    }

    /** @return list<array{name_en: string, name_ar: string}> */
    private function guestClassifications(): array
    {
        return [
            ['name_en' => 'VIP', 'name_ar' => 'VIP'],
            ['name_en' => 'Regular', 'name_ar' => 'عادي'],
        ];
    }
}
