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
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        // S2: per serial number and IP, so one spoofed or looping clock
        // cannot flood ingest. 0 disables the limit.
        RateLimiter::for('device', function (Request $request) {
            $perMinute = (int) config('adms.device_rate_limit', 600);
            if ($perMinute <= 0) {
                return Limit::none();
            }

            // The serial is client-chosen, so also cap each IP across serials.
            // Many clocks can share one NAT address; keep it generous.
            $perIp = (int) config('adms.device_rate_limit_per_ip', 3000);

            return array_values(array_filter([
                Limit::perMinute($perMinute)
                    ->by('sn:'.\App\Http\Middleware\AuthenticateDevice::serialNumber($request).'|'.$request->ip()),
                $perIp > 0 ? Limit::perMinute($perIp)->by('ip:'.$request->ip()) : null,
            ]));
        });

        $this->routes(function () {
            Route::middleware('api')
                ->prefix('api')
                ->group(base_path('routes/api.php'));

            Route::middleware('web')
                ->group(base_path('routes/web.php'));
        });
    }
}
