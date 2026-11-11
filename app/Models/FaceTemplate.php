<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A face template for one employee as held by one terminal.
 *
 * The counterpart of FingerprintTemplate for faces: same (sn, pin, slot) key,
 * same idempotent upsert, but a separate table because a face slot and a finger
 * slot are not the same namespace and because face payloads are far larger.
 *
 * bio_type matters when sending a template back: a near-infrared template (2)
 * is useless to a visible-light terminal (9) and vice versa.
 */
class FaceTemplate extends Model
{
    use HasFactory;

    protected $table = 'face_templates';

    protected $fillable = [
        'device_id',
        'sn',
        'pin',
        'fid',
        'bio_type',
        'size',
        'valid',
        'template',
        'template_hash',
        'idempresa',
        'idoficina',
        'format',
        'version',
        'captured_at',
    ];

    protected $casts = [
        'fid' => 'integer',
        'bio_type' => 'integer',
        'size' => 'integer',
        'valid' => 'integer',
        'captured_at' => 'datetime',
    ];

    public function device()
    {
        return $this->belongsTo(Device::class);
    }

    public function scopeValid($query)
    {
        return $query->where('valid', '>', 0);
    }

    public function scopeForOffice($query, $idempresa, $idoficina)
    {
        return $query->where('idempresa', $idempresa)->where('idoficina', $idoficina);
    }
}
