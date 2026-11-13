<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A stored snapshot of one terminal's data - the row behind the file that
 * DeviceBackupService writes.
 *
 * The row outlives the device on purpose (see the migration), so everything
 * needed to describe a snapshot - serial number, name, size, counts - is kept
 * here rather than read back through the device relation.
 */
class DeviceBackup extends Model
{
    protected $table = 'device_backups';

    protected $fillable = [
        'device_id',
        'serial_number',
        'device_name',
        'file_name',
        'size_bytes',
        'employees_count',
        'fingerprints_count',
        'faces_count',
        'attendances_count',
        'created_by',
    ];

    protected $casts = [
        'size_bytes' => 'integer',
        'employees_count' => 'integer',
        'fingerprints_count' => 'integer',
        'faces_count' => 'integer',
        'attendances_count' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function device()
    {
        return $this->belongsTo(Device::class);
    }

    /**
     * The size as an operator reads it. A snapshot of a busy terminal runs to
     * tens of megabytes, so raw bytes are useless in a table cell.
     */
    public function humanSize(): string
    {
        $bytes = (int) $this->size_bytes;

        if ($bytes >= 1024 * 1024) {
            return number_format($bytes / (1024 * 1024), 1).' MB';
        }

        if ($bytes >= 1024) {
            return number_format($bytes / 1024, 1).' KB';
        }

        return $bytes.' B';
    }
}
