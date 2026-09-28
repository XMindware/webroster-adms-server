<?php

namespace App\Models;

use App\Services\PopulateEmployeesService;
use App\Services\PullFingerprintsService;
use App\Services\PushFingerprintsService;
use App\Services\RemoveEmployeesService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class Device extends Model
{
    use HasFactory;

    protected $table = 'devices';

    protected $fillable = [
        'name',
        'serial_number',
        'online',
        'idreloj',
        'idempresa',
        'idoficina',
        'modelo',
    ];

    protected $casts = [
        'last_alert_sent_at' => 'datetime',
        'updated_at' => 'datetime',
        'online' => 'datetime',
    ];

    /**
     * How far a punch's stored timestamp and created_at may drift before it
     * counts as a clock problem rather than normal write latency.
     */
    private const CLOCK_SKEW_TOLERANCE_MINUTES = 20;

    public function oficina()
    {
        // Link by both idoficina and idempresa (using value, not column)
        // Note: avoid eager loading with this constraint; prefer lazy loading.
        return $this->belongsTo(Oficina::class, 'idoficina', 'idoficina')
            ->where('oficinas.idempresa', $this->idempresa);
    }

    public function getLastAttendance()
    {
        return Attendance::where('sn', $this->serial_number)->orderBy('id', 'desc')->first();
    }

    /**
     * Whether any punch recorded today looks like it came from a terminal whose
     * clock is out of step with the server.
     */
    public function hasClockDiscrepancyToday()
    {
        // Without an office timezone there is nothing to convert into, so the
        // raw created_at/timestamp difference is used as-is.
        if (!$this->oficina || !$this->oficina->timezone) {
            // Only the two timestamps are needed, and only until the first
            // offender is found - so ask the database for exactly that instead
            // of hydrating every row of the day into Eloquent models.
            $rows = Attendance::where('sn', $this->serial_number)
                ->whereDate('created_at', now()->toDateString())
                ->whereBetween('timestamp', self::liveWindow())
                ->select(['created_at', 'timestamp'])
                ->cursor();

            foreach ($rows as $row) {
                if (abs(Carbon::parse($row->created_at)->diffInMinutes(Carbon::parse($row->timestamp))) > self::CLOCK_SKEW_TOLERANCE_MINUTES) {
                    return true;
                }
            }

            return false;
        }

        $officeTimezone = $this->oficina->timezone;
        [$startOfDay, $endOfDay] = self::officeDayWindow($officeTimezone);

        // Cursored rather than ->get(): the question is only "is there at least
        // one row more than the tolerance out of step", so rows are pulled in
        // batches and the search stops at the first hit. ->get() loaded the
        // whole day into Eloquent models with their Carbon casts, which is what
        // exhausted the memory limit on a large table.
        $rows = Attendance::where('sn', $this->serial_number)
            ->whereBetween('created_at', [$startOfDay, $endOfDay])
            ->whereBetween('timestamp', self::liveWindow())
            ->select(['created_at', 'timestamp'])
            ->cursor();

        foreach ($rows as $row) {
            if (self::isSkewed(Carbon::parse($row->created_at), Carbon::parse($row->timestamp), $officeTimezone)) {
                return true;
            }
        }

        return false;
    }

    public function commands()
    {
        return $this->hasMany(Command::class);
    }

    public function webhook()
    {
        return $this->hasOne(Webhook::class, 'device_id');
    }

    public function scopeOnline($query)
    {
        return $query->where('online', true);
    }

    /**
     * Commands waiting to be handed to the terminal.
     *
     * $limit is applied by the database, not by slicing the loaded collection:
     * a fingerprint pull can queue hundreds of commands, and ->get()->take($n)
     * still holds every one of them in memory before returning $n.
     */
    public function pendingCommands(?int $limit = null)
    {
        $query = $this->commands()->pending()->orderBy('id');

        if ($limit !== null && $limit > 0) {
            $query->limit($limit);
        }

        return $query->get();
    }

    public function populate($employees = null)
    {
        try {
            $service = new PopulateEmployeesService($this);

            return $service->run($employees);
        } catch (\Exception $e) {
            \Log::error($e->getMessage());

            return 0;
        }
    }

    /**
     * Queue DATA DELETE USERINFO commands for the given employees on this device.
     * Counterpart of populate(); returns the number of commands queued.
     */
    public function depopulate($employees = null)
    {
        try {
            $service = new RemoveEmployeesService($this);

            return $service->run($employees);
        } catch (\Exception $e) {
            \Log::error($e->getMessage());

            return 0;
        }
    }

    /**
     * Fingerprint templates this terminal has handed back to the server.
     */
    public function fingerprintTemplates()
    {
        return $this->hasMany(FingerprintTemplate::class, 'sn', 'serial_number');
    }

    /**
     * Ask this terminal for fingerprint templates.
     *
     * Bulk mode queues a single CHECK (the terminal decides what to re-upload);
     * targeted mode queues one DATA QUERY FINGERTMP per employee per finger.
     * Templates arrive later, on the terminal's own schedule, via /iclock/cdata.
     *
     * @param array<int, int>|null $fids
     * @return int commands queued
     */
    public function pullFingerprints(bool $bulk = true, $employees = null, ?array $fids = null)
    {
        try {
            $service = app(PullFingerprintsService::class);

            return $bulk
                ? $service->bulk($this)
                : $service->forDevice($this, $employees, $fids);
        } catch (\Exception $e) {
            \Log::error('pullFingerprints failed: ' . $e->getMessage(), ['device_id' => $this->id]);

            return 0;
        }
    }

    /**
     * Distribute stored templates to this terminal. Manual only — see
     * PushFingerprintsService.
     *
     * @param array<int, string|int>|null $pins
     * @return array{candidates: int, commands: int, skipped: int}
     */
    public function pushFingerprints(?array $pins = null)
    {
        try {
            return app(PushFingerprintsService::class)->toDevice($this, $pins);
        } catch (\Exception $e) {
            \Log::error('pushFingerprints failed: ' . $e->getMessage(), ['device_id' => $this->id]);

            return ['candidates' => 0, 'commands' => 0, 'skipped' => 0];
        }
    }

    /**
     * Get current time in the office's timezone
     *
     * @return \Carbon\Carbon|null
     */
    public function getCurrentOfficeTime()
    {
        if (!$this->oficina || !$this->oficina->timezone) {
            return null;
        }

        return now()->setTimezone($this->oficina->timezone);
    }

    /**
     * Convert a datetime to the office's timezone
     *
     * @param  \Carbon\Carbon  $datetime
     * @return \Carbon\Carbon|null
     */
    public function convertToOfficeTimezone($datetime)
    {
        if (!$this->oficina || !$this->oficina->timezone || !$datetime) {
            return $datetime;
        }

        return $datetime->setTimezone($this->oficina->timezone);
    }

    /**
     * How many of today's punches look like they came from a terminal whose
     * clock is out of step. The number counts records, not minutes.
     *
     * @return int
     */
    public function getTimezoneDiscrepancyCount()
    {
        if (!$this->oficina || !$this->oficina->timezone) {
            return 0;
        }

        $officeTimezone = $this->oficina->timezone;
        [$startOfDay, $endOfDay] = self::officeDayWindow($officeTimezone);

        // Two columns, streamed in chunks. This method runs on every terminal
        // poll, so the previous ->get() - which materialised the device's
        // entire day as Eloquent models - was both a full table scan (there
        // was no index on sn) and a per-poll memory spike that grew all day.
        $rows = Attendance::where('sn', $this->serial_number)
            ->whereBetween('created_at', [$startOfDay, $endOfDay])
            ->whereBetween('timestamp', self::liveWindow())
            ->select(['created_at', 'timestamp'])
            ->cursor();

        $discrepancyCount = 0;

        foreach ($rows as $row) {
            if (self::isSkewed(Carbon::parse($row->created_at), Carbon::parse($row->timestamp), $officeTimezone)) {
                $discrepancyCount++;
            }
        }

        return $discrepancyCount;
    }

    /**
     * Discrepancy counts for many devices in one pass.
     *
     * The monitor page used to call getTimezoneDiscrepancyCount() inside a loop
     * over every device, so opening /devices ran one query per terminal. This
     * pulls the day's live rows for the whole fleet in a single query and
     * buckets them in PHP.
     *
     * @param  \Illuminate\Support\Collection<int, Device>  $devices
     * @return array<string, int> keyed by serial_number
     */
    public static function discrepancyCountsFor($devices): array
    {
        $counts = [];
        $timezones = [];
        $windows = [];

        // Only devices with a usable office timezone can have a discrepancy -
        // getTimezoneDiscrepancyCount() returns 0 for the rest, and matching
        // that here keeps the two paths in agreement.
        foreach ($devices as $device) {
            $timezone = optional($device->oficina)->timezone;

            if (!$timezone) {
                continue;
            }

            $counts[$device->serial_number] = 0;
            $timezones[$device->serial_number] = $timezone;

            // The same office-local-day window getTimezoneDiscrepancyCount()
            // builds. Computed per device because two offices can sit on
            // different calendar days.
            $windows[$device->serial_number] = self::officeDayWindow($timezone);
        }

        if ($counts === []) {
            return $counts;
        }

        // One query for the whole fleet. The bounds are the widest any office
        // in the set can need (the app-time day, plus a day either side to
        // absorb the largest offset); the exact per-office window is applied
        // below, so this is a superset filter only.
        $rows = Attendance::whereIn('sn', array_keys($counts))
            ->whereBetween('timestamp', self::liveWindow())
            ->whereBetween('created_at', [now()->subDays(2), now()->addDays(2)])
            ->select(['sn', 'created_at', 'timestamp'])
            ->cursor();

        foreach ($rows as $row) {
            $timezone = $timezones[$row->sn] ?? null;

            if ($timezone === null) {
                continue;
            }

            $createdAt = Carbon::parse($row->created_at);
            $timestamp = Carbon::parse($row->timestamp);

            // Apply the per-office day window that the aggregated query could
            // not express. Without this, rows belonging to a neighbouring day
            // would be counted for offices whose timezone shifts the boundary.
            [$startOfDay, $endOfDay] = $windows[$row->sn];

            if ($createdAt->lt($startOfDay) || $createdAt->gt($endOfDay)) {
                continue;
            }

            if (self::isSkewed($createdAt, $timestamp, $timezone)) {
                $counts[$row->sn]++;
            }
        }

        return $counts;
    }

    /**
     * The office's local calendar day, expressed in the app timezone - which is
     * the timezone Eloquent writes created_at in.
     *
     * Converting these boundaries to UTC instead compared UTC-formatted strings
     * against local-time rows, sliding the window by the app/office offset
     * (seven hours here) and dropping punches that had just arrived.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    private static function officeDayWindow(string $officeTimezone): array
    {
        $appTimezone = config('app.timezone');

        return [
            now($officeTimezone)->startOfDay()->setTimezone($appTimezone),
            now($officeTimezone)->endOfDay()->setTimezone($appTimezone),
        ];
    }

    /**
     * The window a genuinely "live" punch can fall in.
     *
     * A terminal that is replaying its backlog produces rows whose created_at
     * is today but whose timestamp is months old; those are not a clock
     * problem, and counting them saturated this figure (it read 9,559 out of
     * 10,389 rows for a single device on 2026-09-22). A clock fault shows up as
     * minutes or hours, so a one-day window separates the two cleanly.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    private static function liveWindow(): array
    {
        return [now()->subDay(), now()->addDay()];
    }

    /**
     * Whether a punch's created_at and timestamp disagree by more than the
     * tolerance, once both are read in the office's timezone.
     */
    private static function isSkewed(Carbon $createdAt, Carbon $timestamp, string $officeTimezone): bool
    {
        // Convert both to the office timezone before comparing. Carbon 3
        // returns a signed float from diffInMinutes; abs() keeps the meaning
        // this had before the upgrade.
        $attendanceTimeInOfficeTz = $timestamp->setTimezone($officeTimezone);

        return abs($createdAt->setTimezone($officeTimezone)
            ->diffInMinutes($attendanceTimeInOfficeTz)) > self::CLOCK_SKEW_TOLERANCE_MINUTES;
    }
}
