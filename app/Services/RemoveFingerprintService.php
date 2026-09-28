<?php

namespace App\Services;

use App\Models\Device;
use App\Models\FingerprintTemplate;
use App\Services\Adms\AdmsCommandService;
use App\Services\Adms\AdmsProtocol;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Remove individual fingers from individual terminals.
 *
 * The sibling of RemoveEmployeesService, one level finer: DATA DELETE USERINFO
 * drops a person and every template they have, while DATA DELETE FINGERTMP
 * drops one finger and leaves the user record — name, card, password, group —
 * exactly as it was. The case it exists for is a finger enrolled by mistake, or
 * a hand being re-enrolled where the stale slot would otherwise keep matching.
 *
 * Two things this service does beyond queueing the command, both of which are
 * easy to leave out and both of which matter:
 *
 *  1. It marks our own stored rows for that finger invalid. Without this the
 *     terminal forgets the finger but our database still believes it holds a
 *     good template, and the next push — a migration, a bulk push, a repair —
 *     quietly puts the deleted finger back. The finger would come back weeks
 *     later with nobody able to explain why.
 *
 *  2. It queues before it invalidates. The reverse order would leave the worst
 *     of the two half-done states: our records say the finger is gone, the
 *     terminal still has it, and nothing will ever correct the difference,
 *     because a template we believe invalid is never pushed again.
 *
 * @author XMindware
 */
class RemoveFingerprintService
{
    /**
     * Finger indices a terminal can hold. The protocol has no "all fingers"
     * spelling — ZKTeco's SDK defines only the PIN+FID form — so removing every
     * finger means one command per index.
     */
    public const FIDS = [0, 1, 2, 3, 4, 5, 6, 7, 8, 9];

    protected AdmsCommandService $commands;

    public function __construct(?AdmsCommandService $commands = null)
    {
        $this->commands = $commands ?? app(AdmsCommandService::class);
    }

    /**
     * Queue the removal of the given fingers on the given terminals.
     *
     * @param  iterable<Device> $devices
     * @param  array<int, int>  $fids    finger indices; null entries are dropped
     * @return array{commands: int, devices: int, invalidated: int, fids: array<int, int>}
     */
    public function run(iterable $devices, string|int|null $pin, array $fids): array
    {
        $pin = (string) $pin;

        $fids = collect($fids)
            ->filter(fn ($fid) => $fid !== null && $fid !== '')
            ->map(fn ($fid) => (int) $fid)
            ->unique()
            ->sort()
            ->values()
            ->all();

        $devices = collect($devices)->filter()->values();

        if ($pin === '' || $fids === [] || $devices->isEmpty()) {
            return ['commands' => 0, 'devices' => $devices->count(), 'invalidated' => 0, 'fids' => $fids];
        }

        $queued = 0;

        foreach ($devices as $device) {
            foreach ($fids as $fid) {
                // skipIfPending: a second click should not double the queue, and
                // unlike a push there is nothing to re-send — the first command
                // already says everything there is to say.
                $command = $this->commands->queue(
                    $device,
                    AdmsProtocol::deleteFingerTmp($pin, $fid),
                    AdmsProtocol::TYPE_FINGERTMP_DELETE,
                    "PIN:{$pin}/FID:{$fid}",
                    true
                );

                if ($command !== null) {
                    $queued++;
                }
            }
        }

        $invalidated = $this->invalidate($devices, $pin, $fids);

        $result = [
            'commands' => $queued,
            'devices' => $devices->count(),
            'invalidated' => $invalidated,
            'fids' => $fids,
        ];

        Log::info('RemoveFingerprintService: finger removal queued', array_merge($result, [
            'pin' => $pin,
            'device_ids' => $devices->pluck('id')->all(),
        ]));

        return $result;
    }

    /**
     * Mark the rows we hold for these fingers as no longer valid.
     *
     * Scoped by serial number, not by office: a template row describes what one
     * terminal holds, so removing a finger on one unit must not claim the other
     * units lost it too.
     *
     * The row is kept rather than deleted. It still records the size, the hash
     * and when it was captured, and if the finger is enrolled again the ingest
     * path updates this same row (sn, pin, fid is unique) and sets valid back
     * to 1 from the terminal's own report.
     *
     * @param  Collection<int, Device> $devices
     * @param  array<int, int>         $fids
     * @return int rows actually changed
     */
    protected function invalidate(Collection $devices, string $pin, array $fids): int
    {
        $sns = $devices->pluck('serial_number')->filter()->unique()->values()->all();

        if ($sns === []) {
            return 0;
        }

        return FingerprintTemplate::query()
            ->whereIn('sn', $sns)
            ->where('pin', $pin)
            ->whereIn('fid', $fids)
            // Only rows that are changing, so the number we report back is what
            // actually happened rather than how many rows were merely looked at.
            ->where('valid', '>', 0)
            ->update(['valid' => 0]);
    }
}
