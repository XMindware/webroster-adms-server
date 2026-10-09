<?php

namespace App\Http\Middleware;

use App\Models\EmpresaApiKey;
use Closure;
use Illuminate\Http\Request;

/**
 * Authenticates the machine-to-machine calls WebRoster makes to /api/empresa/*
 * with "Authorization: Bearer <empresa api key>".
 *
 * The empresa the key belongs to is exposed as the request attribute
 * "idempresa", and every controller query is scoped to it. Reading the empresa
 * from a query string or body field instead would turn a guessed serial number
 * into another customer's device data.
 */
class AuthenticateEmpresa
{
    public function handle(Request $request, Closure $next)
    {
        $plaintext = $request->bearerToken();

        $key = $plaintext ? EmpresaApiKey::findActive($plaintext) : null;

        if (!$key) {
            return response()->json(['message' => 'Unauthenticated.'], 401, ['WWW-Authenticate' => 'Bearer']);
        }

        // WebRoster polls this endpoint on a timer, so writing last_used_at on
        // every call would add a pointless UPDATE to that path. Once a minute
        // is enough to tell an operator when a key was last used, and the write
        // is quiet so it does not touch updated_at or fire model events.
        if (!$key->last_used_at || $key->last_used_at->lt(now()->subMinute())) {
            $key->forceFill(['last_used_at' => now()])->saveQuietly();
        }

        $request->attributes->set('idempresa', (string) $key->idempresa);
        $request->attributes->set('empresa_api_key', $key);

        return $next($request);
    }
}
