<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Command extends Model
{
    use HasFactory;

    protected $table = 'device_commands';

    protected $fillable = [
        'device_id',
        'command',
        'type',
        'reference',
        'data',
        'response',
        'executed_at',
        'completed_at',
        'failed_at',
    ];

    protected $casts = [
        'executed_at' => 'datetime',
        'completed_at' => 'datetime',
        'failed_at' => 'datetime',
    ];

    public $timestamps = [
        'completed_at',
        'failed_at',
    ];

    public function device()
    {
        return $this->belongsTo(Device::class);
    }


    public function scopePending($query)
    {
        return $query->whereNull('executed_at');
    }

    public function scopeExecuted($query)
    {
        return $query->whereNotNull('executed_at');
    }

    public function scopeCompleted($query)
    {
        return $query->whereNotNull('completed_at');
    }

    public function scopeFailed($query)
    {
        return $query->whereNotNull('failed_at');
    }

    public function scopeByDevice($query, $device)
    {
        return $query->where('device_id', $device->id);
    }

    public function scopeOfType($query, string $type)
    {
        return $query->where('type', $type);
    }

    /**
     * Sent to the terminal but never acknowledged on /iclock/devicecmd — the
     * commands worth looking at when a fingerprint pull produced nothing.
     */
    public function scopeUnacknowledged($query)
    {
        return $query->whereNotNull('executed_at')
            ->whereNull('completed_at')
            ->whereNull('failed_at');
    }

}
