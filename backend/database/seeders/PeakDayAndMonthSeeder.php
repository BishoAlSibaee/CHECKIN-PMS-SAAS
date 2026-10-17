<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\PeakDay;
use App\Models\PeakMonth;

class PeakDayAndMonthSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Seed weekdays
        $days = [
            ['day_name_en' => 'Saturday',   'day_name_ar' => 'السبت'],
            ['day_name_en' => 'Sunday',     'day_name_ar' => 'الأحد'],
            ['day_name_en' => 'Monday',    'day_name_ar' => 'الإثنين'],
            ['day_name_en' => 'Tuesday',   'day_name_ar' => 'الثلاثاء'],
            ['day_name_en' => 'Wednesday', 'day_name_ar' => 'الأربعاء'],
            ['day_name_en' => 'Thursday',  'day_name_ar' => 'الخميس'],
            ['day_name_en' => 'Friday',    'day_name_ar' => 'الجمعة'],
        ];

        foreach ($days as $day) {
            PeakDay::updateOrCreate(
                ['day_name_en' => $day['day_name_en']],
                ['day_name_ar' => $day['day_name_ar'], 'check' => 0]
            );
        }

        // Seed months
        $months = [
            ['month_name_en' => 'January',   'month_name_ar' => 'يناير'],
            ['month_name_en' => 'February',  'month_name_ar' => 'فبراير'],
            ['month_name_en' => 'March',     'month_name_ar' => 'مارس'],
            ['month_name_en' => 'April',     'month_name_ar' => 'أبريل'],
            ['month_name_en' => 'May',       'month_name_ar' => 'مايو'],
            ['month_name_en' => 'June',      'month_name_ar' => 'يونيو'],
            ['month_name_en' => 'July',      'month_name_ar' => 'يوليو'],
            ['month_name_en' => 'August',    'month_name_ar' => 'أغسطس'],
            ['month_name_en' => 'September', 'month_name_ar' => 'سبتمبر'],
            ['month_name_en' => 'October',   'month_name_ar' => 'أكتوبر'],
            ['month_name_en' => 'November',  'month_name_ar' => 'نوفمبر'],
            ['month_name_en' => 'December',  'month_name_ar' => 'ديسمبر'],
        ];

        foreach ($months as $month) {
            PeakMonth::updateOrCreate(
                ['month_name_en' => $month['month_name_en']],
                ['month_name_ar' => $month['month_name_ar'], 'check' => 0]
            );
        }
    }
}
