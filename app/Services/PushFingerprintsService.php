<?php

namespace App\Services;

use App\Models\Device;
use App\Models\FingerprintTemplate;
use App\Services\Adms\AdmsCommandService;
use App\Services\Adms\AdmsProtocol;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Distributes stored templates back out to a terminal
 * (DATA UPDATE FINGERTMP), so a finger enrolled once on one device can be
 * recognised on the others in the same office.
 *
 * Deliberately manual: nothing in the sync path calls this. It runs only when
 * someone asks for it, from the UI or from `php artisan fingerprints:push`.
 * Pushing templates rewrites biometric data on a live terminal, and doing that
 * automatically on every roster sync is not a decision this service should make
 * on its own.
 *
 * @author XMindware
 */
class PushFingerprintsService
{
    public const TYPE_PUSH_FP = AdmsProtocol::TYPE_PUSH_FINGERTMP;

    protected AdmsCommandService $commands;

    public function __construct(?AdmsCommandService $commands = null)
    {
        $this->commands = $commands ?? app(AdmsCommandService::class);
    }

    /**
     * Queue a DATA UPDATE FINGERTMP for every template the office holds that
     * this device is missing (or holds a different version of).
     *
     * @param array<int, string|int>|null $pins limit to these employees
     * @return array{candidates: int, commands: int, skipped: int}
     */
    public function toDevice(Device $device, ?array $pins = null): array
    {
        // Decide what to send using metadata only; the templates themselves are
        // loaded one at a time when a command is actually built.
        $templates = $this->templatesForOffice($device, $pins);

        if ($templates->isEmpty()) {
            Log::info('PushFingerprintsService: nothing to distribute', [
                'device_id' => $device->id,
            ]);

            return ['candidates' => 0, 'commands' => 0, 'skipped' => 0];
        }

        // What the target already holds, keyed by "pin/fid" => hash. Only the
        // three columns needed — never pull the blobs just to compare hashes.
        $existing = FingerprintTemplate::where('sn', $device->serial_number)
            ->get(['pin', 'fid', 'template_hash'])
            ->mapWithKeys(fn (FingerprintTemplate $t) => ["{$t->pin}/{$t->fid}" => $t->template_hash])
            ->all();

        $commands = 0;
        $skipped = 0;

        foreach ($templates as $template) {
            $key = "{$template->pin}/{$template->fid}";

            if (($existing[$key] ?? null) === $template->template_hash) {
                $skipped++;
                continue;
            }

            $commands += $this->queue($device, $template);
        }

        Log::info('PushFingerprintsService: distribution queued', [
            'device_id' => $device->id,
            'sn' => $device->serial_number,
            'candidates' => $templates->count(),
            'commands' => $commands,
            'skipped' => $skipped,
        ]);

        return [
            'candidates' => $templates->count(),
            'commands' => $commands,
            'skipped' => $skipped,
        ];
    }

    /**
     * The best template per (pin, fid) available in this device's office,
     * captured on any terminal. Invalid templates are never distributed.
     *
     * @param array<int, string|int>|null $pins
     * @return Collection<int, FingerprintTemplate>
     */
    protected function templatesForOffice(Device $device, ?array $pins = null): Collection
    {
        return FingerprintTemplate::query()
            ->where('idempresa', $device->idempresa)
            ->where('idoficina', $device->idoficina)
            ->valid()
            ->when($pins !== null, fn ($q) => $q->whereIn('pin', $pins))
            ->orderByDesc('captured_at')
            ->orderByDesc('id')
            // Metadata only. An office of 300 employees with ten fingers each
            // is ~6 MB of base64; there is no reason to hold all of it in
            // memory just to work out what needs sending.
            ->get(['id', 'pin', 'fid', 'size', 'valid', 'template_hash', 'captured_at'])
            // Newest capture wins when several terminals hold the same finger.
            ->unique(fn (FingerprintTemplate $t) => "{$t->pin}/{$t->fid}")
            ->values();
    }

    protected function queue(Device $device, FingerprintTemplate $template): int
    {
        $reference = "PIN:{$template->pin}/FID:{$template->fid}";

        // Fetch the blob only now that we know it is going out.
        $payload = FingerprintTemplate::whereKey($template->id)->value('template');

        if ($payload === null || $payload === '') {
            Log::warning('PushFingerprintsService: template row has no payload', [
                'template_id' => $template->id,
            ]);

            return 0;
        }

        $command = $this->commands->queue(
            $device,
            AdmsProtocol::updateFingerTmp(
                $template->pin,
                $template->fid,
                $template->size,
                $template->valid,
                $payload
            ),
            self::TYPE_PUSH_FP,
            $reference,
            skipIfPending: true
        );

        return $command !== null ? 1 : 0;
    }
}
