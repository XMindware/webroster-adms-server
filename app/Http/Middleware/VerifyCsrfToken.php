<?php

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken as Middleware;

class VerifyCsrfToken extends Middleware
{
    /**
     * The URIs that should be excluded from CSRF verification.
     *
     * Every /iclock/ endpoint is called by the terminal itself, never by a
     * browser, so it can never carry a CSRF token. Missing one here makes the
     * device receive a 419 and silently stop pushing data — which looks exactly
     * like a network problem from the outside.
     *
     * @var array<int, string>
     */
    protected $except = [
        'iclock/cdata',
        'iclock/getrequest',
        'iclock/devicecmd',
        'iclock/querydata',
        'iclock/upload-log',
    ];
}
