<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

use App\Models\Command;
use App\Models\Oficina;
use App\Models\Attendance;
use App\Services\PopulateEmployeesService;
use App\Services\PullFingerprintsService;
use App\Services\PushFingerprintsService;
use App\Services\RemoveEmployeesService;

class Device extends Model
{
    use HasFactory;

    protected $table = 'devices';

    protected $fillable = [
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

    public function hayDesfasesHoy()
    {
        $hayDesfases = false;

        // Check if device has an office with timezone
        if (!$this->oficina || !$this->oficina->timezone) {
            // If no timezone info, use default behavior
            $checadasHoy = Attendance::where('sn', $this->serial_number)
                ->whereDate('created_at', now()->toDateString())
                ->get();

            foreach ($checadasHoy as $attendance) {
                if (abs($attendance->created_at->diffInMinutes($attendance->timestamp)) > 20) {
                    $hayDesfases = true;
                    break;
                }
            }
            return $hayDesfases;
        }

        // Get today's range in office timezone and convert to UTC for querying
        $officeTimezone = $this->oficina->timezone;
        $startOfDayUtc = now($officeTimezone)->startOfDay()->setTimezone('UTC');
        $endOfDayUtc = now($officeTimezone)->endOfDay()->setTimezone('UTC');

        // Get today's attendances for this device based on office local date
        $checadasHoy = Attendance::where('sn', $this->serial_number)
            ->whereBetween('created_at', [$startOfDayUtc, $endOfDayUtc])
            ->get();

        // go through the attendances and check if there are differences between created_at and timestamp for more than 20min
        foreach ($checadasHoy as $attendance) {
            // Convert attendance timestamp to office timezone for proper comparison
            $attendanceTimeInOfficeTz = $attendance->timestamp->setTimezone($officeTimezone);
            
            // Calculate difference in minutes between when the record was created and the actual attendance time
            // Both times are now in the same timezone (office timezone)
            // Carbon 3 returns a signed float; abs() keeps the pre-upgrade meaning.
            $diffInMinutes = abs($attendance->created_at->setTimezone($officeTimezone)
                ->diffInMinutes($attendanceTimeInOfficeTz));
            
            if ($diffInMinutes > 20) {
                $hayDesfases = true;
                break;
            }
        }
        
        return $hayDesfases;
    }

    public function commands()
    {
        return $this->hasMany(Command::class);
    }

    public function scopeOnline($query)
    {
        return $query->where('online', true);
    }

    public function pendingCommands()
    {
        return $this->commands()->pending()->get();
    }

    public function populate($employees = null)
    {
        try {
            $service = new PopulateEmployeesService($this);
            return $service->run($employees);
        } catch (\Exception $e) {
            // log the error
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
     * @param \Carbon\Carbon $datetime
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
     * Get timezone discrepancy count for today
     * @return int
     */
    public function getTimezoneDiscrepancyCount()
    {
        if (!$this->oficina || !$this->oficina->timezone) {
            return 0;
        }

        // Get today's range in office timezone and convert to UTC for querying
        $officeTimezone = $this->oficina->timezone;
        $startOfDayUtc = now($officeTimezone)->startOfDay()->setTimezone('UTC');
        $endOfDayUtc = now($officeTimezone)->endOfDay()->setTimezone('UTC');

        $checadasHoy = Attendance::where('sn', $this->serial_number)
            ->whereBetween('created_at', [$startOfDayUtc, $endOfDayUtc])
            ->get();
        
        $discrepancyCount = 0;
        
        foreach ($checadasHoy as $attendance) {
            $attendanceTimeInOfficeTz = $attendance->timestamp->setTimezone($officeTimezone);
            $diffInMinutes = abs($attendance->created_at->setTimezone($officeTimezone)
                ->diffInMinutes($attendanceTimeInOfficeTz));
            
            if ($diffInMinutes > 20) {
                $discrepancyCount++;
            }
        }
        
        return $discrepancyCount;
    }
}
