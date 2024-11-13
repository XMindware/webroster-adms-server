<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Attendance extends Model
{
    use HasFactory;

    protected $fillable = [
        'employee_id',
        'timestamp',
        'sn',
        'idreloj',
        'status1',
        'status2',
        'status3',
        'status4',
        'status5',
        'response_uniqueid',
    ];

    protected $casts = [
        'timestamp' => 'datetime',
        'status1' => 'boolean',
        'status2' => 'boolean',
        'status3' => 'boolean',
        'status4' => 'boolean',
        'status5' => 'boolean',
    ];

    // relation between attendance and device by the sn
    public function device()
    {
        return $this->belongsTo(Device::class, 'sn', 'serial_number');
    }
}