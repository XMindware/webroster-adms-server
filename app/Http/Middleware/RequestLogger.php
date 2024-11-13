<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class RequestLogger
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        // Log the incoming request
        Log::channel('request_log')->info('Request Logged', [
            'method' => $request->getMethod(),
            'url' => $request->fullUrl(),
            'headers' => $request->headers->all(),
            'body' => $request->all(),
        ]);

        // Process the request and get the response
        $response = $next($request);

        // Log the response
        Log::channel('request_log')->info('Response Logged', [
            'status' => $response->status(),
            'content' => $response->getContent(),
        ]);

        return $response;
    }
}