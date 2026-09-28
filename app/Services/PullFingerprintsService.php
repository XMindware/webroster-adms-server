<?php

namespace App\Services;

use App\Models\Agente;
use App\Models\Device;
use App\Models\Oficina;
use App\Services\Adms\AdmsCommandService;
use App\Services\Adms\AdmsProtocol;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Queues the ADMS commands that make a terminal hand its fingerprint templates
 * back to the server.
 *
 * Two mechanisms, because neither alone is enough in practice:
 *
 *  - Bulk (`CHECK`): the terminal re-uploads the data it holds. One command,
 *    no knowledge of the roster needed — the right tool for adopting a device
 *    that was already enrolled in the field. What exactly a `CHECK` re-sends
 *    depends on firmware and on the TransFlag from the handshake, so it is
 *    best-effort.
 *
 *  - Targeted (`DATA QUERY FINGERTMP PIN=x FingerID=n`): asks for one finger of
 *    one employee. Precise and firmware-independent, but costs one command per
 *    finger, so pulling a full office this way is expensive.
 *
 * Either way the templates come back asynchronously: the terminal POSTs them to
 * /iclock/cdata, where iclockController hands them to FingerprintIngestService.
 * Nothing here blocks waiting for data.
 *
 * @author XMindware
 */
class PullFingerprintsService
{
    /*
     * Aliases for the canonical AdmsProtocol constants. Kept so callers and
     * rows already stored under these names keep working.
     */
    public const TYPE_CHECK = AdmsProtocol::TYPE_PULL_CHECK;
    public const TYPE_QUERY_FP = AdmsProtocol::TYPE_PULL_FINGERTMP;
    public const TYPE_QUERY_USER = AdmsProtocol::TYPE_PULL_USERINFO;

    protected AdmsCommandService $commands;

    public function __construct(?AdmsCommandService $commands = null)
    {
        $this->commands = $commands ?? app(AdmsCommandService::class);
    }

    /**
     * Bulk pull: ask the terminal to re-upload everything it holds.
     *
     * @return int number of commands queued
     */
    public function bulk(Device $device): int
    {
        $queued = 0;

        $queued += $this->queue(
            $device,
            AdmsProtocol::check(),
            self::TYPE_CHECK,
            'device:' . $device->id
        );

        if (config('adms.query_userinfo', true)) {
            $queued += $this->queue(
                $device,
                AdmsProtocol::queryUserinfo(),
                self::TYPE_QUERY_USER,
                'device:' . $device->id
            );
        }

        Log::info('PullFingerprintsService: bulk pull queued', [
            'device_id' => $device->id,
            'sn' => $device->serial_number,
            'commands' => $queued,
        ]);

        return $queued;
    }

    /**
     * Targeted pull for a set of employees on one device.
     *
     * @param iterable<Agente>|null $employees defaults to the device's office roster
     * @param array<int, int>|null  $fids      finger slots, defaults to config('adms.finger_ids')
     * @return int number of commands queued
     */
    public function forDevice(Device $device, $employees = null, ?array $fids = null): int
    {
        $employees = $this->resolveEmployees($device, $employees);

        if ($employees->isEmpty()) {
            Log::info('PullFingerprintsService: no employees to pull', [
                'device_id' => $device->id,
            ]);

            return 0;
        }

        $fids = $this->resolveFingerIds($fids);
        $budget = (int) config('adms.max_pull_commands', 2000);
        $queued = 0;

        foreach ($employees as $employee) {
            $pin = $employee->idagente ?? null;

            if ($pin === null || $pin === '') {
                continue;
            }

            foreach ($fids as $fid) {
                if ($queued >= $budget) {
                    Log::warning('PullFingerprintsService: command budget reached', [
                        'device_id' => $device->id,
                        'budget' => $budget,
                    ]);

                    return $queued;
                }

                $queued += $this->queue(
                    $device,
                    AdmsProtocol::queryFingerTmp($pin, $fid),
                    self::TYPE_QUERY_FP,
                    "PIN:{$pin}/FID:{$fid}"
                );
            }
        }

        Log::info('PullFingerprintsService: targeted pull queued', [
            'device_id' => $device->id,
            'sn' => $device->serial_number,
            'employees' => $employees->count(),
            'fingers' => count($fids),
            'commands' => $queued,
        ]);

        return $queued;
    }

    /**
     * Targeted pull for a single PIN on one device.
     *
     * @param array<int, int>|null $fids
     */
    public function forPin(Device $device, $pin, ?array $fids = null): int
    {
        $employee = Agente::withTrashed()
            ->where('idagente', $pin)
            ->where('idempresa', $device->idempresa)
            ->where('idoficina', $device->idoficina)
            ->first();

        // The PIN may exist on the terminal without a local roster row (that is
        // exactly the case worth pulling), so fall back to a bare stand-in.
        $employee ??= new Agente(['idagente' => $pin]);

        return $this->forDevice($device, collect([$employee]), $fids);
    }

    /**
     * Ask one terminal for a single employee's user record.
     *
     * This is the "get info user" operation: `DATA QUERY USERINFO PIN=x` makes
     * the terminal re-upload that user's record, which lands in
     * fingerprint_templates / agentes through the normal /iclock/cdata path.
     *
     * Distinct from bulk(), which asks for the whole roster with a trailing
     * empty PIN. It is also distinct from forPin(), which only asks for
     * templates — a terminal can hold a user record whose templates were never
     * enrolled, and that is a case worth being able to inspect.
     *
     * $withTemplates additionally queues the ten targeted finger queries, which
     * is what an operator usually wants when they say "pull this person".
     *
     * @return int number of commands queued
     */
    public function userInfo(Device $device, $pin, bool $withTemplates = true, ?array $fids = null): int
    {
        if ($pin === null || $pin === '') {
            return 0;
        }

        $queued = $this->queue(
            $device,
            AdmsProtocol::queryUserinfo($pin),
            self::TYPE_QUERY_USER,
            "PIN:{$pin}"
        );

        if ($withTemplates) {
            $queued += $this->forPin($device, $pin, $fids);
        }

        Log::info('PullFingerprintsService: user info pull queued', [
            'device_id' => $device->id,
            'sn' => $device->serial_number,
            'pin' => $pin,
            'with_templates' => $withTemplates,
            'commands' => $queued,
        ]);

        return $queued;
    }

    /**
     * Pull across every device in an office.
     *
     * @param array<int, int>|null $fids
     * @return array{devices: int, commands: int}
     */
    public function forOffice(Oficina $oficina, bool $bulk = true, ?array $fids = null): array
    {
        $devices = Device::where('idempresa', $oficina->idempresa)
            ->where('idoficina', $oficina->idoficina)
            ->get();

        $commands = 0;

        foreach ($devices as $device) {
            $commands += $bulk
                ? $this->bulk($device)
                : $this->forDevice($device, null, $fids);
        }

        return [
            'devices' => $devices->count(),
            'commands' => $commands,
        ];
    }

    /**
     * Queue one command unless an identical one is already pending for this
     * device — a second click should not double the queue.
     *
     * @param  string $payload an AdmsProtocol payload builder, unframed
     * @return int 1 when queued, 0 when skipped as duplicate
     */
    protected function queue(Device $device, string $payload, string $type, ?string $reference = null): int
    {
        $command = $this->commands->queue($device, $payload, $type, $reference, skipIfPending: true);

        return $command !== null ? 1 : 0;
    }

    /**
     * @param iterable<Agente>|null $employees
     * @return Collection<int, Agente>
     */
    protected function resolveEmployees(Device $device, $employees): Collection
    {
        if ($employees instanceof Collection) {
            return $employees->values();
        }

        if ($employees !== null) {
            return collect($employees)->values();
        }

        return Agente::where('idempresa', $device->idempresa)
            ->where('idoficina', $device->idoficina)
            ->get()
            ->values();
    }

    /**
     * @param array<int, int>|null $fids
     * @return array<int, int>
     */
    protected function resolveFingerIds(?array $fids): array
    {
        $fids ??= config('adms.finger_ids', range(0, 9));

        $fids = array_values(array_unique(array_map('intval', (array) $fids)));

        return array_values(array_filter($fids, fn (int $fid) => $fid >= 0 && $fid <= 9));
    }
}
