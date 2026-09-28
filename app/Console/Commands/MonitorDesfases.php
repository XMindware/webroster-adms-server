<?php

namespace App\Console\Commands;

use App\Models\Attendance;
use App\Models\Device;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * Report attendance punches whose stored timestamp and created_at disagree.
 *
 * The command name is kept in Spanish ("desfases" = skew) because it is
 * referenced by the scheduler; see app/Console/Kernel.php.
 */
class MonitorDesfases extends Command
{
    protected $signature = 'monitor:desfases {--threshold=5 : Threshold in minutes for detecting skew}';

    protected $description = 'Monitor attendance records for time skew between timestamp and created_at';

    public function handle()
    {
        $threshold = (int) $this->option('threshold');
        $this->info("Starting skew monitoring with a threshold of {$threshold} minutes...");

        $skewed = $this->detectSkewed($threshold);

        if ($skewed->count() > 0) {
            $this->warn("Found {$skewed->count()} punches with skew:");

            foreach ($skewed as $attendance) {
                $diffMinutes = abs($attendance->created_at->diffInMinutes($attendance->timestamp));
                $device = $attendance->device;
                $oficina = $device ? $device->oficina : null;

                $this->line("ID: {$attendance->id} | Employee: {$attendance->employee_id} | Device: {$attendance->sn}");
                $this->line("Timestamp: {$attendance->timestamp} | Created: {$attendance->created_at}");
                $this->line("Difference: {$diffMinutes} minutes | Office: " . ($oficina ? $oficina->ubicacion : 'N/A'));
                $this->line('---');

                // Details of the skew found. Deliberately not logged to a file,
                // to avoid file-permission problems.
                $this->warn('SKEW DETECTED:');
                $this->warn("  - Punch ID: {$attendance->id}");
                $this->warn("  - Employee: {$attendance->employee_id}");
                $this->warn("  - Device: {$attendance->sn}");
                $this->warn('  - Office: ' . ($oficina ? $oficina->ubicacion : 'N/A'));
                $this->warn("  - Difference: {$diffMinutes} minutes");
            }

            $this->error('Skew was detected in the punches. See the output above for details.');
        } else {
            $this->info('No skew found in recent punches.');
        }

        return Command::SUCCESS;
    }

    /**
     * Punches from the last 24 hours whose timestamp and created_at disagree by
     * more than $threshold minutes.
     */
    protected function detectSkewed($threshold)
    {
        $since = Carbon::now()->subDay();

        return Attendance::with(['device.oficina'])
            ->where('created_at', '>=', $since)
            ->get()
            ->filter(function ($attendance) use ($threshold) {
                // Carbon 3 returns a signed float; abs() keeps the pre-upgrade meaning.
                $diffMinutes = abs($attendance->created_at->diffInMinutes($attendance->timestamp));

                // With an office timezone, compare both sides in that timezone.
                if ($attendance->device && $attendance->device->oficina && $attendance->device->oficina->timezone) {
                    $officeTimezone = $attendance->device->oficina->timezone;

                    $attendanceTimeInOfficeTz = $attendance->timestamp->setTimezone($officeTimezone);
                    $createdTimeInOfficeTz = $attendance->created_at->setTimezone($officeTimezone);

                    $diffMinutes = abs($createdTimeInOfficeTz->diffInMinutes($attendanceTimeInOfficeTz));
                }

                return $diffMinutes > $threshold;
            });
    }
}
