<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\DeviceRequestMetadata;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * One-off clean-up for S3: before metadata-only logging, device_log and
 * finger_log stored raw request bodies (fingerprint and face templates).
 * Rewrites those rows to the same metadata new rows get, and optionally
 * deletes log files written before the fix.
 */
class ScrubLoggedPayloads extends Command
{
    protected $signature = 'logs:scrub-payloads
        {--force : Run without asking for confirmation}
        {--with-files : Also delete storage/logs/request_response.log and laravel*.log}';

    protected $description = 'Remove request payloads (biometric templates) from device_log, finger_log and, optionally, log files';

    public function handle(): int
    {
        if (! $this->option('force') && ! $this->confirm('Rewrite device_log and finger_log rows to metadata only? This cannot be undone.')) {
            return self::FAILURE;
        }

        $deviceLogs = $this->scrubTable('device_log', function (object $row): array {
            return ['data' => json_encode(DeviceRequestMetadata::filter($this->decode($row->data)))];
        });

        $fingerLogs = $this->scrubTable('finger_log', function (object $row): array {
            return [
                'url' => json_encode(DeviceRequestMetadata::filter($this->decode($row->url))),
                'data' => json_encode(DeviceRequestMetadata::bodySummary((string) $row->data)),
            ];
        });

        $this->info("Scrubbed {$deviceLogs} device_log and {$fingerLogs} finger_log rows.");

        if ($this->option('with-files')) {
            $files = array_merge(
                File::glob(storage_path('logs/request_response*.log')),
                File::glob(storage_path('logs/laravel*.log')),
            );
            foreach ($files as $file) {
                File::delete($file);
                $this->line('Deleted '.basename($file));
            }
        } else {
            $this->warn('Log files written before this fix still hold payloads. Re-run with --with-files to delete them.');
        }

        return self::SUCCESS;
    }

    /**
     * @param  callable(object): array<string, string>  $rewrite
     */
    private function scrubTable(string $table, callable $rewrite): int
    {
        $count = 0;
        DB::table($table)->chunkById(500, function ($rows) use ($table, $rewrite, &$count) {
            foreach ($rows as $row) {
                DB::table($table)->where('id', $row->id)->update($rewrite($row));
                $count++;
            }
        });

        return $count;
    }

    /**
     * @return array<mixed>
     */
    private function decode(?string $json): array
    {
        $decoded = json_decode((string) $json, true);

        // Old handshake rows stored json_encode($body), i.e. a JSON string.
        return is_array($decoded) ? $decoded : [];
    }
}
