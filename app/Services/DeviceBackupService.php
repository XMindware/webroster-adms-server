<?php

namespace App\Services;

use App\Models\Agente;
use App\Models\Attendance;
use App\Models\Device;
use App\Models\DeviceBackup;
use App\Models\FacePhoto;
use App\Models\FaceTemplate;
use App\Models\FingerprintTemplate;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Snapshots everything the server holds for one terminal into a single JSON
 * file, and hands it back for download.
 *
 * What this is not: it does not talk to the terminal. Nothing is queued, no
 * ADMS command is sent. Asking a device to re-upload what it holds is the
 * existing "pull fingerprints" action; this one archives what the server
 * already has, so it survives a bad migration, an accidental delete or a
 * database that has to be rebuilt.
 *
 * The file is written by streaming rather than by building an array first.
 * A busy terminal carries hundreds of thousands of punches, and json_encode()
 * over one big array of them is exactly the shape that hits PHP's memory limit
 * - the same failure that took /devices and /iclock/getrequest down before.
 * Each section is therefore written row by row through a cursor, so peak memory
 * stays flat no matter how much history the terminal has.
 *
 * @see DeviceController::backup()
 */
class DeviceBackupService
{
    /** Disk the files live on. See config/filesystems.php. */
    public const DISK = 'local';

    /** Directory on that disk, relative to storage/app. */
    public const DIRECTORY = 'device-backups';

    /** Written into every file so a reader can tell what it is looking at. */
    public const FORMAT = 'adms-device-backup';

    /** Bumped only when the layout changes in a way an older reader cannot handle. */
    public const VERSION = 1;

    /**
     * Take a snapshot of $device and record it.
     *
     * $actor is stored verbatim; it is the email (or id) of whoever pressed
     * the button, so an archive found months later can be attributed.
     *
     * @throws \RuntimeException when the file cannot be written
     */
    public function create(Device $device, ?string $actor = null): DeviceBackup
    {
        $disk = Storage::disk(self::DISK);

        if (! $disk->exists(self::DIRECTORY)) {
            $disk->makeDirectory(self::DIRECTORY);
        }

        $fileName = self::DIRECTORY.'/'.$this->fileName($device);
        $final = $disk->path($fileName);
        $temporary = $final.'.part';

        // A full history can take a while to serialise. The default 30 s web
        // timeout would cut the response off mid-file.
        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }

        $handle = fopen($temporary, 'wb');

        if ($handle === false) {
            throw new \RuntimeException("Unable to open {$temporary} for writing.");
        }

        try {
            $counts = $this->writeSnapshot($handle, $device, $actor);
        } catch (\Throwable $e) {
            fclose($handle);
            @unlink($temporary);

            throw $e;
        }

        fclose($handle);

        // Renamed only once the file is complete. A crash halfway through must
        // never leave a truncated file that the list page would happily offer
        // to download as a valid backup.
        if (! @rename($temporary, $final)) {
            @unlink($temporary);

            throw new \RuntimeException("Unable to store the backup file at {$final}.");
        }

        return DeviceBackup::create([
            'device_id' => $device->id,
            'serial_number' => $device->serial_number,
            'device_name' => $device->name,
            'file_name' => $fileName,
            'size_bytes' => (int) @filesize($final),
            'employees_count' => $counts['employees'],
            'fingerprints_count' => $counts['fingerprints'],
            'faces_count' => $counts['faces'],
            'attendances_count' => $counts['attendances'],
            'created_by' => $actor,
        ]);
    }

    /**
     * Is the file behind this row still on the server?
     *
     * Files can disappear independently of the rows - storage cleared during a
     * deploy, an operator deleting the folder by hand - so the download path
     * checks instead of trusting the table.
     */
    public function exists(DeviceBackup $backup): bool
    {
        return Storage::disk(self::DISK)->exists($backup->file_name);
    }

    /**
     * Send the stored file to the browser.
     *
     * @throws \Symfony\Component\HttpKernel\Exception\NotFoundHttpException
     */
    public function download(DeviceBackup $backup): StreamedResponse
    {
        abort_unless($this->exists($backup), 404, __('devices.backup_file_missing'));

        return Storage::disk(self::DISK)->download(
            $backup->file_name,
            basename($backup->file_name),
            ['Content-Type' => 'application/json']
        );
    }

    /**
     * Remove both the file and the row.
     *
     * The row goes even when the file was already missing, otherwise a backup
     * whose storage was wiped can never be cleared from the list.
     */
    public function delete(DeviceBackup $backup): void
    {
        Storage::disk(self::DISK)->delete($backup->file_name);
        $backup->delete();
    }

    /**
     * Write the whole document and return how many rows each section held.
     *
     * @param resource $handle
     * @return array{employees: int, fingerprints: int, faces: int, attendances: int}
     */
    private function writeSnapshot($handle, Device $device, ?string $actor): array
    {
        $office = $device->oficina;

        fwrite($handle, "{\n");
        fwrite($handle, $this->field('format', self::FORMAT));
        fwrite($handle, $this->field('version', self::VERSION));
        fwrite($handle, $this->field('generated_at', now()->toIso8601String()));
        fwrite($handle, $this->field('generated_by', $actor));
        fwrite($handle, $this->field('device', $device->attributesToArray()));
        fwrite($handle, $this->field('office', $office?->attributesToArray()));

        // The office roster: the people this terminal is supposed to hold.
        $employees = $this->writeCollection($handle, 'employees', Agente::query()
            ->where('idempresa', $device->idempresa)
            ->where('idoficina', $device->idoficina)
            ->orderBy('idagente')
            ->cursor());

        // Biometrics are keyed by serial number, not by device id: the rows
        // describe what one unit holds, and they outlive a device row that has
        // been re-registered.
        $fingerprints = $this->writeCollection($handle, 'fingerprint_templates', FingerprintTemplate::query()
            ->where('sn', $device->serial_number)
            ->orderBy('pin')
            ->orderBy('fid')
            ->cursor());

        $faces = $this->writeCollection($handle, 'face_templates', FaceTemplate::query()
            ->where('sn', $device->serial_number)
            ->orderBy('pin')
            ->orderBy('fid')
            ->cursor());

        $photos = $this->writeCollection($handle, 'face_photos', FacePhoto::query()
            ->where('sn', $device->serial_number)
            ->orderBy('pin')
            ->cursor());

        $attendances = $this->writeCollection($handle, 'attendances', Attendance::query()
            ->where('sn', $device->serial_number)
            ->orderBy('id')
            ->cursor());

        // Last, because the totals are only known once every section is out.
        // JSON is order-free, so a reader that wants them first can still have
        // them first.
        fwrite($handle, '  "counts": '.$this->encode([
            'employees' => $employees,
            'fingerprints' => $fingerprints,
            'face_templates' => $faces,
            'face_photos' => $photos,
            'attendances' => $attendances,
        ])."\n");
        fwrite($handle, "}\n");

        return [
            'employees' => $employees,
            'fingerprints' => $fingerprints,
            'faces' => $faces + $photos,
            'attendances' => $attendances,
        ];
    }

    /**
     * Write one `"key": [ ... ],` section, one row at a time.
     *
     * @param resource $handle
     * @param iterable<int, Model> $rows
     * @return int rows written
     */
    private function writeCollection($handle, string $key, iterable $rows): int
    {
        fwrite($handle, '  '.$this->encode($key).': [');

        $count = 0;

        foreach ($rows as $row) {
            fwrite($handle, ($count === 0 ? "\n    " : ",\n    ").$this->encode($row->attributesToArray()));
            $count++;
        }

        fwrite($handle, $count === 0 ? "],\n" : "\n  ],\n");

        return $count;
    }

    /**
     * One scalar field line, comma-terminated.
     */
    private function field(string $key, mixed $value): string
    {
        return '  '.$this->encode($key).': '.$this->encode($value).",\n";
    }

    /**
     * JSON for one value.
     *
     * JSON_INVALID_UTF8_SUBSTITUTE because employee names arrive from the
     * terminals and an old unit can send a byte sequence that is not valid
     * UTF-8. Without it json_encode() returns false and the whole backup dies
     * on one bad row.
     */
    private function encode(mixed $value): string
    {
        return json_encode(
            $value,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
        );
    }

    /**
     * A readable, sortable file name: serial number plus timestamp.
     *
     * Two backups of the same terminal in the same second would collide, so a
     * suffix is added until the name is free rather than overwriting a file
     * that an earlier row still points at.
     */
    private function fileName(Device $device): string
    {
        $serial = preg_replace('/[^A-Za-z0-9_-]/', '-', (string) $device->serial_number) ?: 'device';
        $base = sprintf('%s-%s', $serial, now()->format('Ymd-His'));

        $candidate = $base.'.json';
        $suffix = 2;

        while (Storage::disk(self::DISK)->exists(self::DIRECTORY.'/'.$candidate)) {
            $candidate = $base.'-'.$suffix.'.json';
            $suffix++;
        }

        return $candidate;
    }
}
