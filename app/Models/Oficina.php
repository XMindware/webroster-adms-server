<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Oficina extends Model
{
    use HasFactory;

    protected $table = 'oficinas';

    protected $fillable = [
        'idempresa',
        'idoficina',        
        'ubicacion',
        'iatacode',
        'city_timezone',
        'timezone',
        'public_url',
        'token',
    ];

    public $timestamps = [
        'created_at',
        'updated_at',
    ];

    public function public_url()
    {
        return $this->public_url;
    }
}
