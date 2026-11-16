<?php

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken as Middleware;

class VerifyCsrfToken extends Middleware
{
    /**
     * The URIs that should be excluded from CSRF verification.
     *
     * @var array<int, string>
     */
    protected $except = [
        // Clocks have no session and cannot send a token.
        // Covered by tests/Feature/DeviceCsrfContractTest.php.
        'iclock/cdata',
        'iclock/getrequest',
        'iclock/devicecmd',
        'iclock/querydata',
        // S8: clocks cannot send a token; uploadLog checks the serial number
        // against registered devices and caps size and lines instead.
        'iclock/upload-log',
    ];
}
