<?php

namespace App\Console\Commands;

use App\Models\AppNotification;
use App\Models\Hotel;
use App\Services\NotificationService;
use App\Services\Taqnyat\TaqnyatCredentialStore;
use App\Services\Taqnyat\TaqnyatService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Periodic low-balance check for the Taqnyat SMS subscription — an internal
 * staff alert only (AppNotification), never a message to a guest. At most
 * one "sms_balance_low" notification per hotel per calendar day, even if
 * this runs hourly, so staff aren't spammed while the balance stays low.
 */
class CheckSmsBalanceCommand extends Command
{
    protected $signature = 'taqnyat:check-balance';

    protected $description = 'Check each hotel\'s Taqnyat SMS balance and alert staff internally when it runs low';

    public function handle(TaqnyatCredentialStore $credentialStore, TaqnyatService $taqnyatService, NotificationService $notifications): int
    {
        $threshold = (float) config('services.taqnyat.low_balance_threshold', 50);
        $checked = 0;
        $alerted = 0;

        foreach (Hotel::pluck('id') as $hotelId) {
            if (! $credentialStore->load((int) $hotelId)) {
                continue;
            }

            $checked++;

            try {
                $result = $taqnyatService->getBalance((int) $hotelId);
            } catch (\Throwable $e) {
                report($e);
                Log::warning('Taqnyat balance check failed', ['hotel_id' => $hotelId, 'error' => $e->getMessage()]);
                continue;
            }

            if (! $result['configured'] || isset($result['error']) || ! isset($result['balance'])) {
                continue;
            }

            if ($result['balance'] >= $threshold) {
                continue;
            }

            $alreadyNotifiedToday = AppNotification::where('hotel_id', $hotelId)
                ->where('type', 'sms_balance_low')
                ->whereDate('created_at', today())
                ->exists();

            if ($alreadyNotifiedToday) {
                continue;
            }

            $notifications->notify(
                (int) $hotelId,
                'sms_balance_low',
                'NOTIFICATIONS.SMS_BALANCE_LOW_TITLE',
                ['balance' => $result['balance'], 'currency' => $result['currency'] ?? 'SAR'],
                '/admin/external-subscriptions',
                'manage external subscriptions'
            );
            $alerted++;
        }

        $this->info("Checked {$checked} hotel(s) with Taqnyat credentials; alerted {$alerted}.");

        return self::SUCCESS;
    }
}
