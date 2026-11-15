<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Agente extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $table = 'agentes';

    protected $fillable = [
        'idempresa',
        'idoficina',
        'idagente',
        'shortname',
        'fullname',
        'fingerprint_data',
        'device_removal_queued_at',
    ];

    protected $casts = [
        'device_removal_queued_at' => 'datetime',
    ];

    public function oficina()
    {
        return $this->belongsTo(Oficina::class, 'idoficina', 'idoficina');
    }

    /**
     * Agents that were removed from the station (soft-deleted locally) but whose
     * DATA DELETE USERINFO command has not yet been pushed to the devices.
     */
    public function scopePendingDeviceRemoval($query)
    {
        return $query->onlyTrashed()->whereNull('device_removal_queued_at');
    }
}
