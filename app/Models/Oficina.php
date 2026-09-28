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

    /**
     * Whether oficinas.timezone holds a generic zone instead of a real one.
     *
     * "UTC", "GMT", "Etc/*" and bare offsets ("+00:00") are all valid IANA
     * identifiers, so OficinaController::normalizeTimezone() accepts them and
     * they look perfectly fine in the table. A physical office with a building
     * and a terminal in it is never actually in one of them - they end up here
     * when the field was defaulted or never filled in.
     *
     * Leaving one in place costs two things:
     *
     *  - the monitor card computes "today" over the wrong 24 hours, and
     *  - getrequest() builds "SET OPTIONS DateTime=" from it. An Indonesian
     *    office recorded as UTC makes the server order a 7 hour shift, the
     *    terminal obeys, its punches then look skewed, and the next poll
     *    orders another correction - a feedback loop that never settles.
     *
     * Treat these as "not filled in yet": warn about them and never hand a
     * terminal a clock built from one.
     */
    public function timezoneIsGeneric(): bool
    {
        $timezone = trim((string) $this->timezone);

        if ($timezone === '') {
            return true;
        }

        return preg_match('#^(?:UTC|GMT|Z|Etc/.+|[+-]\d{2}:?\d{2})$#i', $timezone) === 1;
    }
}
