<?php

namespace Tests\Support;

use App\Models\ReservationDailyCharge;
use Carbon\Carbon;
use Database\Seeders\ReservationTestDataSeeder;
use Illuminate\Support\Facades\Artisan;

trait FinanceTestBootstrap
{
    protected const FINANCE_PERIOD_START = '2026-08-01';

    protected const FINANCE_PERIOD_END = '2026-08-31';

    protected const FINANCE_CASH_START = '2026-06-01';

    protected const FINANCE_CASH_END = '2026-06-30';

    private static bool $financeBootstrapped = false;

    private static ?\App\Models\User $financeBootUser = null;

    protected function bootstrapFinanceData(bool $setAugustNow = true): \App\Models\User
    {
        if ($setAugustNow) {
            Carbon::setTestNow('2026-08-15 12:00:00');
        }

        if (! self::$financeBootstrapped) {
            $hasAugustCharges = ReservationDailyCharge::query()
                ->whereBetween('charge_date', [self::FINANCE_PERIOD_START, self::FINANCE_PERIOD_END])
                ->exists();

            if (! $hasAugustCharges) {
                $this->artisan('db:seed', [
                    '--class' => ReservationTestDataSeeder::class,
                    '--force' => true,
                    '--no-interaction' => true,
                ]);
                $this->artisan('reservations:backfill-daily-charges', ['--sync-base' => true]);
            }

            Artisan::call('accounting:backfill-journal');

            self::$financeBootUser = $this->userWithApiPermissions([
                'view reports',
                'view financial reports',
                'view accounting reports',
                'view revenue',
                'view earnings',
                'manage refunds',
            ]);
            self::$financeBootstrapped = true;
        } else {
            Artisan::call('accounting:backfill-journal');
        }

        return self::$financeBootUser ?? $this->userWithApiPermissions([
            'view reports',
            'view financial reports',
            'view accounting reports',
            'view revenue',
            'view earnings',
        ]);
    }

    protected function resetFinanceTestNow(): void
    {
        Carbon::setTestNow();
    }

    protected function financeReportUrl(string $slug, ?string $start = null, ?string $end = null, array $extra = []): string
    {
        $params = array_merge([
            'start_date' => $start ?? self::FINANCE_PERIOD_START,
            'end_date' => $end ?? self::FINANCE_PERIOD_END,
        ], $this->defaultReportQueryExtras($slug), $extra);

        return '/api/users/reports/'.$slug.'?'.http_build_query($params);
    }

    /**
     * @return array<string, string>
     */
    protected function defaultReportQueryExtras(string $slug): array
    {
        return $slug === 'general-ledger' ? ['account_type' => 'asset'] : [];
    }

    /**
     * @return array<string, mixed>
     */
    protected function fetchFinanceReport(\App\Models\User $user, string $slug, ?string $start = null, ?string $end = null, array $extra = []): array
    {
        $response = $this->actingAs($user, 'api')->getJson(
            $this->financeReportUrl($slug, $start, $end, $extra)
        );
        $response->assertOk();
        $response->assertJsonPath('success', true);

        return $response->json('data') ?? [];
    }

    /**
     * @return array<string, mixed>
     */
    protected function fetchFullFinanceReportViaHttp(
        \App\Models\User $user,
        string $slug,
        ?string $start = null,
        ?string $end = null,
        array $extra = []
    ): array {
        $page = 1;
        $lastPage = 1;
        $allRows = [];
        $payload = [];

        do {
            $query = array_merge([
                'start_date' => $start ?? self::FINANCE_PERIOD_START,
                'end_date' => $end ?? self::FINANCE_PERIOD_END,
                'page' => $page,
            ], $this->defaultReportQueryExtras($slug), $extra);

            $response = $this->actingAs($user, 'api')->getJson(
                '/api/users/reports/'.$slug.'?'.http_build_query($query)
            );
            $response->assertOk();
            $response->assertJsonPath('success', true);

            $payload = $response->json('data') ?? [];
            $allRows = array_merge($allRows, $payload['rows'] ?? []);
            $lastPage = (int) ($payload['last_page'] ?? 1);
            $page++;
        } while ($page <= $lastPage);

        $payload['rows'] = $allRows;

        return $payload;
    }
}
