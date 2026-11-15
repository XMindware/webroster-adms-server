<?php

namespace App\Console\Commands;

use App\Models\Device;
use App\Models\Oficina;
use App\Services\PushFingerprintsService;
use Illuminate\Console\Command;

/**
 * Distribute stored templates to terminals.
 *
 *   php artisan fingerprints:push --device=4
 *   php artisan fingerprints:push --office=20 --pins=1234,1235
 *
 * Never scheduled: this rewrites biometric data on a live terminal, so it runs
 * only when a person asks for it.
 *
 * @author XMindware
 */
class PushFingerprints extends Command
{
    protected $signature = 'fingerprints:push
        {--device= : Device id or serial number}
        {--office= : idoficina — every device in that office}
        {--pins= : Comma-separated employee PINs, default all stored}
        {--dry-run : Report what would be queued without queueing it}';

    protected $description = 'Send stored fingerprint templates to a terminal (DATA UPDATE FINGERTMP).';

    public function handle(PushFingerprintsService $service): int
    {
        $devices = $this->resolveDevices();

        if ($devices->isEmpty()) {
            $this->error('No devices matched. Pass --device or --office.');

            return self::FAILURE;
        }

        $pins = $this->option('pins')
            ? array_values(array_filter(array_map('trim', explode(',', (string) $this->option('pins')))))
            : null;

        if ($this->option('dry-run')) {
            $this->warn('Dry run — nothing will be queued.');
        }

        $total = 0;

        foreach ($devices as $device) {
            if ($this->option('dry-run')) {
                $this->line(sprintf(
                    '%-24s would be evaluated for %s',
                    $device->name ?: $device->serial_number,
                    $pins ? count($pins) . ' PIN(s)' : 'every stored template'
                ));
                continue;
            }

            $result = $service->toDevice($device, $pins);
            $total += $result['commands'];

            $this->line(sprintf(
                '%-24s %d queued, %d already up to date (of %d candidates)',
                $device->name ?: $device->serial_number,
                $result['commands'],
                $result['skipped'],
                $result['candidates']
            ));
        }

        $this->info("Queued {$total} template push(es).");

        return self::SUCCESS;
    }

    /**
     * @return \Illuminate\Support\Collection<int, Device>
     */
    protected function resolveDevices()
    {
        if ($office = $this->option('office')) {
            $oficina = Oficina::where('idoficina', $office)->first();

            if (!$oficina) {
                return collect();
            }

            return Device::where('idempresa', $oficina->idempresa)
                ->where('idoficina', $oficina->idoficina)
                ->get();
        }

        if ($device = $this->option('device')) {
            return Device::where('id', $device)
                ->orWhere('serial_number', $device)
                ->get();
        }

        return collect();
    }
}
