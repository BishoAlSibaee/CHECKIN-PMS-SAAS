<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

class RouteServiceProvider extends ServiceProvider
{
    /**
     * The path to your application's "home" route.
     *
     * Typically, users are redirected here after authentication.
     *
     * @var string
     */
    public const HOME = '/home';

    /**
     * Define your route model bindings, pattern filters, and other route configuration.
     */
    public function boot(): void
    {
        RateLimiter::for('api', function (Request $request) {
            if ($request->user()) {
                return Limit::perMinute((int) env('API_RATE_LIMIT_AUTHENTICATED', 600))
                    ->by('user:'.$request->user()->id);
            }

            return Limit::perMinute((int) env('API_RATE_LIMIT_GUEST', 120))
                ->by($request->ip());
        });

        RateLimiter::for('login', function (Request $request) {
            return Limit::perMinute((int) env('API_RATE_LIMIT_LOGIN', 20))
                ->by($request->ip());
        });

        RateLimiter::for('report-download', function (Request $request) {
            return Limit::perMinute((int) env('API_RATE_LIMIT_REPORT_DOWNLOAD', 30))
                ->by($request->ip());
        });

        RateLimiter::for('kiosk', function (Request $request) {
            return Limit::perMinute((int) env('API_RATE_LIMIT_KIOSK', 120))
                ->by($request->ip());
        });

        RateLimiter::for('channex-webhook', function (Request $request) {
            return Limit::perMinute(120)->by($request->ip());
        });

        RateLimiter::for('grms-webhook', function (Request $request) {
            return Limit::perMinute(120)->by($request->ip());
        });

        $this->routes(function () {
            Route::middleware('api')
                ->prefix('api')
                ->group(base_path('routes/api.php'));

            Route::middleware('api')
                ->prefix('api/users')
                ->group(base_path('routes/users.php'));

            Route::middleware('api')
                ->prefix('api/platform-admin')
                ->group(base_path('routes/platform_admin.php'));

            Route::middleware('api')
                ->prefix('api/reservations')
                ->group(base_path('routes/reservations.php'));

            // Deliberately NOT using the 'api' middleware group here — it carries
            // Laravel's built-in throttle:api, whose limiter closure calls
            // $request->user(), which resolves through Passport's TokenGuard. That
            // blows up on a kiosk device token (not a Passport token) since it runs
            // before our own kiosk.auth middleware gets a chance to authenticate it.
            Route::middleware(['throttle:kiosk', \Illuminate\Routing\Middleware\SubstituteBindings::class])
                ->prefix('api/kiosk')
                ->group(base_path('routes/kiosk.php'));

            // Channex calls this unauthenticated (it's not our staff/kiosk) —
            // verified instead via a shared-secret header (see ChannexWebhookController).
            Route::middleware(['throttle:channex-webhook', \Illuminate\Routing\Middleware\SubstituteBindings::class])
                ->prefix('api/channex')
                ->group(base_path('routes/channex.php'));

            // GRMS calls this unauthenticated (it's not our staff/kiosk) —
            // verified instead via a shared-secret header (see GrmsWebhookController).
            Route::middleware(['throttle:grms-webhook', \Illuminate\Routing\Middleware\SubstituteBindings::class])
                ->prefix('api/grms')
                ->group(base_path('routes/grms.php'));

            Route::middleware('web')
                ->group(base_path('routes/web.php'));
        });
    }
}
