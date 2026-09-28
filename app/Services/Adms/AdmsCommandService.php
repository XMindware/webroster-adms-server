<?php

namespace App\Services\Adms;

use App\Models\Command;
use App\Models\Device;
use App\Services\CommandIdService;
use Illuminate\Support\Facades\Log;

/**
 * The one way to put a command in a terminal's queue.
 *
 * Queueing a command used to be an eight-line ritual repeated at every call
 * site: allocate a command id, build the "C:{id}:…" line, then
 * `$device->commands()->create([...])` with `device_id` set a second time (the
 * relation already does it) and `executed_at => null` (already the default).
 * Some sites tagged the row with a `type` and some did not, so half the queue
 * was unattributable when a terminal acknowledged it.
 *
 * Everything now goes through queue(): allocate the id, frame the payload, and
 * tag the row, in one place. The optional skipIfPending flag is the
 * deduplication the pull/push pipelines need — a second click should not double
 * the queue.
 *
 * @see AdmsProtocol for the payloads this frames.
 */
class AdmsCommandService
{
    protected CommandIdService $commandIdService;

    public function __construct(?CommandIdService $commandIdService = null)
    {
        $this->commandIdService = $commandIdService ?? app(CommandIdService::class);
    }

    /**
     * Queue one command on one device.
     *
     * @param string $payload an AdmsProtocol payload builder, unframed
     * @param string|null $type an AdmsProtocol::TYPE_* constant
     * @param string|null $reference what this command is about, e.g. "PIN:1234/FID:0",
     *   so an acknowledgement can be traced back to it
     * @param bool $skipIfPending queue nothing when an equivalent command is
     *   already waiting on this device
     * @return Command|null the queued command, or null when it was skipped
     */
    public function queue(
        Device $device,
        string $payload,
        ?string $type = null,
        ?string $reference = null,
        bool $skipIfPending = false
    ): ?Command {
        if ($skipIfPending && $this->isAlreadyPending($device, $type, $reference)) {
            Log::debug('AdmsCommandService: equivalent command already pending, skipping', [
                'device_id' => $device->id,
                'type' => $type,
                'reference' => $reference,
            ]);

            return null;
        }

        $cmdId = $this->commandIdService->getNextCmdId();

        return $device->commands()->create([
            'command' => $cmdId,
            'type' => $type,
            'reference' => $reference,
            'data' => AdmsProtocol::frame($cmdId, $payload),
        ]);
    }

    /**
     * Queue the same payload on several devices, one command each.
     *
     * @param iterable<Device> $devices
     * @return int number of commands queued
     */
    public function queueMany(
        iterable $devices,
        string $payload,
        ?string $type = null,
        ?string $reference = null,
        bool $skipIfPending = false
    ): int {
        $queued = 0;

        foreach ($devices as $device) {
            if ($this->queue($device, $payload, $type, $reference, $skipIfPending) !== null) {
                $queued++;
            }
        }

        return $queued;
    }

    /**
     * Is an equivalent command already waiting for this device?
     *
     * A null $type or $reference is left out of the comparison rather than
     * matched against NULL, so callers can dedupe on either dimension alone.
     */
    protected function isAlreadyPending(Device $device, ?string $type, ?string $reference): bool
    {
        return $device->commands()
            ->pending()
            ->when($type !== null, fn ($q) => $q->where('type', $type))
            ->when($reference !== null, fn ($q) => $q->where('reference', $reference))
            ->exists();
    }
}
