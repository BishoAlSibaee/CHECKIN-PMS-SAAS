<?php

namespace App\Providers;

use App\Models\Reservation;
use App\Models\ReservationPay;
use App\Observers\ReservationObserver;
use App\Observers\ReservationPayObserver;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Reservation::observe(ReservationObserver::class);
        ReservationPay::observe(ReservationPayObserver::class);
    }
}
