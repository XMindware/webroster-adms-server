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
        $candidates = self::serialCandidates($request);
        $sn = $candidates[0] ?? '';
        $conflict = count(array_unique($candidates)) > 1;

        $enforce = config('adms.device_auth') === self::MODE_ENFORCE;

        try {
            $device = $sn === '' || $conflict ? null : Device::where('serial_number', $sn)->first(['id', 'enabled']);
        } catch (\Throwable $e) {
            // Log mode must never take a clock offline because of this check.
            // Class name only: the message can carry SQL or bound values.
            $this->warnOnce('lookup-failed|'.$e::class.'|'.$sn, 'device auth: lookup failed', [
                'sn' => mb_substr($sn, 0, 64),
                'exception' => $e::class,
                'mode' => config('adms.device_auth'),
            ]);

            return $enforce ? $this->forbidden() : $next($request);
        }

        $reason = match (true) {
            $conflict => 'conflicting',
            $device === null => 'unregistered',
            ! $device->enabled => 'disabled',
            default => null,
        };

        if ($reason === null) {
            return $next($request);
        }

        $this->report($request, $sn, $reason);

        return $enforce ? $this->forbidden() : $next($request);
    }

    private function forbidden(): Response
    {
        return response('ERROR: Device not registered', 403)
            ->header('Content-Type', 'text/plain');
    }

    /**
     * The serial number this request claims: query SN first, then the body.
     */
    public static function serialNumber(Request $request): string
    {
        return self::serialCandidates($request)[0] ?? '';
    }

    /**
     * Every non-empty serial the request carries, in order: query SN, query
     * sn, body SN, body sn (form or JSON). Terminals send SN in the query
     * string; upload-log sends it as "sn". A non-scalar value counts as a
     * conflict so it can never be silently ignored.
     *
     * @return list<string>
     */
    private static function serialCandidates(Request $request): array
    {
        $body = $request->isJson() ? $request->json()->all() : $request->request->all();
        $sources = [
            $request->query->all()['SN'] ?? null,
            $request->query->all()['sn'] ?? null,
            $body['SN'] ?? null,
            $body['sn'] ?? null,
        ];

        $values = [];
        foreach ($sources as $value) {
            if ($value === null) {
                continue;
            }
            $values[] = is_scalar($value) ? trim((string) $value) : "\0invalid";
        }

        return array_values(array_filter($values, fn (string $v) => $v !== ''));
    }

    /**
     * Terminals poll every few seconds; warn once per serial per hour.
     */
    private function report(Request $request, string $sn, string $reason): void
    {
        $this->warnOnce($reason.'|'.$sn, 'device auth: '.$reason.' serial number', [
            'sn' => mb_substr($sn, 0, 64),
            'ip' => $request->ip(),
            'path' => '/'.ltrim($request->path(), '/'),
            'mode' => config('adms.device_auth'),
        ]);
    }

    /**
     * Reporting must never change the response: a failing cache or log
     * channel is swallowed. If only the cache fails, the warning is still
     * written (undeduplicated) so the problem stays visible.
     *
     * @param  array<string, mixed>  $context
     */
    private function warnOnce(string $dedupeKey, string $message, array $context): void
    {
        try {
            try {
                if (! Cache::add('device-auth-warned:'.sha1($dedupeKey), true, now()->addHour())) {
                    return;
                }
            } catch (\Throwable) {
                // fall through and log without dedupe
            }

            Log::warning($message, $context);
        } catch (\Throwable) {
            // nothing sensible left to do
        }
    }
}
