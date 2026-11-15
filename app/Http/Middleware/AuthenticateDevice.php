<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Device;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * S2: admit only registered, enabled serial numbers on /iclock/*.
 *
 * config('adms.device_auth'):
 *  - 'log' (default): unknown or disabled serials are reported and still
 *    served, so the live fleet can be checked before enforcing.
 *  - 'enforce': they get 403 and nothing reaches the controller, so no
 *    device row is auto-created and no punch or template is stored.
 */
class AuthenticateDevice
{
    public const MODE_LOG = 'log';

    public const MODE_ENFORCE = 'enforce';

    public function handle(Request $request, Closure $next): Response
    {
        $sn = self::serialNumber($request);
        $device = $sn === '' ? null : Device::where('serial_number', $sn)->first(['id', 'enabled']);

        $reason = match (true) {
            $device === null => 'unregistered',
            ! $device->enabled => 'disabled',
            default => null,
        };

        if ($reason === null) {
            return $next($request);
        }

        $this->report($request, $sn, $reason);

        if (config('adms.device_auth') !== self::MODE_ENFORCE) {
            return $next($request);
        }

        return response('ERROR: Device not registered', 403)
            ->header('Content-Type', 'text/plain');
    }

    /**
     * Terminals send SN in the query string; upload-log sends it as "sn".
     */
    public static function serialNumber(Request $request): string
    {
        $sn = $request->query('SN') ?? $request->input('SN') ?? $request->input('sn') ?? '';

        return is_scalar($sn) ? trim((string) $sn) : '';
    }

    /**
     * Terminals poll every few seconds; warn once per serial per hour.
     */
    private function report(Request $request, string $sn, string $reason): void
    {
        $key = 'device-auth-warned:'.sha1($reason.'|'.$sn);

        if (! Cache::add($key, true, now()->addHour())) {
            return;
        }

        Log::warning('device auth: '.$reason.' serial number', [
            'sn' => mb_substr($sn, 0, 64),
            'ip' => $request->ip(),
            'path' => '/'.ltrim($request->path(), '/'),
            'mode' => config('adms.device_auth'),
        ]);
    }
}
