<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps the headers that attendance terminals receive from /iclock/* exactly
 * as they were before the framework upgrade.
 *
 * Symfony 7 (Laravel 11+) writes the default charset in lower case
 * ("text/html; charset=utf-8"); Symfony 6 wrote "charset=UTF-8". The two are
 * equivalent for any standards-compliant client, but the clients here are
 * embedded firmware we cannot test against every model of, so the terminals
 * keep getting the bytes they have always been sent.
 */
class PreserveDeviceResponseHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($request->is('iclock/*')
            && $response->headers->get('Content-Type') === 'text/html; charset=utf-8') {
            $response->headers->set('Content-Type', 'text/html; charset=UTF-8');
        }

        return $response;
    }
}
