<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        // Full resync of availability/rates to Channex-connected OTA channels —
        // ahead of the night audit so any drift is caught before it's audited.
        $schedule->command('channex:push-ari')->dailyAt('02:00');

        // Mandatory safety net for the webhook (see ChannexPollBookingRevisionsCommand) —
        // the booking-revisions feed is a 30-minute non-durable window.
        $schedule->command('channex:poll-booking-revisions')->everyFifteenMinutes();

        // Airbnb "Request to Book" manual-review policy: re-notify staff on
        // each hotel's own configured cadence, and separately resolve any
        // request that's about to blow past its 24h response deadline.
        $schedule->command('channex:remind-pending-airbnb-requests')->everyFiveMinutes();
        $schedule->command('channex:resolve-expiring-airbnb-requests')->everyFiveMinutes();

        // Reconciles the day that just ended (occupancy, books, ZATCA) for
        // every active hotel — a report to review, not a lock on the day.
        $schedule->command('night-audit:run')->dailyAt('03:00');

        // Internal staff alert only when the Taqnyat SMS balance runs low —
        // never a message to a guest.
        $schedule->command('taqnyat:check-balance')->hourly();

        // Heads-up before a tenant's subscription lapses and locks them out
        // (see Tenant::isAccessAllowed) — staff can't renew it themselves,
        // only a platform operator can, so this just gives them time to reach out.
        $schedule->command('subscriptions:warn-expiring')->dailyAt('08:00');
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
