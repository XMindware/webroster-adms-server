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
        // Clocks have no session and cannot send a token; the device
        // endpoints are protected by serial-number checks instead (S2).
        // Covered by tests/Feature/DeviceCsrfContractTest.php.
        'iclock/*',
    ];
}
