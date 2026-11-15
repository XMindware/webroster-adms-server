<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A single biometric template (one finger of one employee) as held by one
 * terminal.
 *
 * @author XMindware
 */
class FingerprintTemplate extends Model
{
    use HasFactory;

    protected $table = 'fingerprint_templates';

    protected $fillable = [
        'device_id',
        'sn',
        'pin',
        'fid',
        'size',
        'valid',
        'duress',
        'template',
        'template_hash',
        'idempresa',
        'idoficina',
        'source',
        'format',
        'version',
        'captured_at',
    ];

    protected $casts = [
        'fid' => 'integer',
        'size' => 'integer',
        'valid' => 'integer',
        'duress' => 'integer',
        'captured_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function device()
    {
        return $this->belongsTo(Device::class);
    }

    /**
     * The employee this template belongs to. PIN on the terminal is
     * agentes.idagente.
     */
    public function agente()
    {
        return $this->belongsTo(Agente::class, 'pin', 'idagente');
    }

    public function scopeValid($query)
    {
        return $query->where('valid', '>', 0);
    }

    public function scopeForOffice($query, $idempresa, $idoficina)
    {
        return $query->where('idempresa', $idempresa)->where('idoficina', $idoficina);
    }

    /**
     * The most recent template for each (pin, fid) pair in an office,
     * regardless of which terminal captured it. This is the set you would
     * distribute to a device that is missing enrolments.
     */
    public function scopeLatestPerFinger($query)
    {
        return $query->orderBy('pin')->orderBy('fid')->orderByDesc('captured_at');
    }
}
