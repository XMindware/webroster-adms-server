<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DeviceLog extends Model
{
    use HasFactory;

    protected $table = 'device_log';

    protected $fillable = [
        'id',
        'data',
        'tgl',
        'sn',
        'option',
        'url'
    ];

    public function device()
    {
        return $this->belongsTo(Device::class, 'idreloj', 'id');
    }

}
