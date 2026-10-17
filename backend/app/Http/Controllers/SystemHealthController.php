<?php

namespace App\Http\Controllers;

use App\Models\Reservation;
use App\Models\ReservationDailyCharge;
use App\Support\SchemaTableHealth;
use Illuminate\Support\Facades\DB;

class SystemHealthController extends Controller
{
    public function index()
    {
        try {
            $checks = [];

            $checks[] = $this->check('database', function () {
                DB::connection()->getPdo();

                return 'Connected';
            });

            $checks[] = $this->check('reservations_table', function () {
                if (! SchemaTableHealth::isUsable('reservations')) {
                    throw new \RuntimeException(
                        'Reservations table missing or MySQL 1932 orphan — run: php artisan db:repair-orphaned-tables'
                    );
                }

                return Reservation::count().' rows';
            });

            $checks[] = $this->check('daily_charges_table', function () {
                if (! SchemaTableHealth::isUsable('reservation_daily_charges')) {
                    throw new \RuntimeException('Missing reservation_daily_charges table');
                }

                return ReservationDailyCharge::count().' rows';
            });

            $failed = collect($checks)->where('status', 'fail')->count();

            return \SuccessData('System health', [
                'healthy' => $failed === 0,
                'mail_configured' => $this->isMailConfigured(),
                'checks' => $checks,
                'timestamp' => now()->toIso8601String(),
            ]);
        } catch (\Exception $e) {
            return \Failed($e->getMessage());
        }
    }

    private function check(string $name, callable $fn): array
    {
        try {
            $message = $fn();

            return ['name' => $name, 'status' => 'pass', 'message' => (string) $message];
        } catch (\Throwable $e) {
            return ['name' => $name, 'status' => 'fail', 'message' => $e->getMessage()];
        }
    }

    private function isMailConfigured(): bool
    {
        $default = (string) config('mail.default', '');
        if (in_array($default, ['log', 'array'], true)) {
            return false;
        }

        $host = config('mail.mailers.' . $default . '.host');

        return is_string($host) && $host !== '';
    }
}
