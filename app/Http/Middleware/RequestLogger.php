<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use App\Support\DeviceRequestMetadata;
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
        $routesToLog = [
            'iclock/cdata',
            'iclock/getrequest',
            'iclock/devicecmd',
            // Add more routes here if needed
        ];

        // Check if the current route is in the routes to log
        if (in_array($request->path(), $routesToLog)) {
            
            // Metadata only (S3): bodies carry biometric templates, headers
            // can carry credentials, and responses carry queued commands that
            // may include templates being pushed to the device.
            $response = $next($request);

            Log::channel('request_log')->info('Device request', DeviceRequestMetadata::fromRequest($request) + [
                'status' => $response->getStatusCode(),
                'response_bytes' => strlen((string) $response->getContent()),
            ]);

            return $response;
        }

        // If route is not in routesToLog, process request without logging
        return $next($request);
    }
}