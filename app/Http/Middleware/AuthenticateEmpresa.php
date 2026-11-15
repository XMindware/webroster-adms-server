<?php

namespace App\Http\Middleware;

use App\Models\EmpresaApiKey;
use Closure;
use Illuminate\Http\Request;

/**
 * Authenticates machine-to-machine calls from WebRoster with a per-empresa API key
 * sent as "Authorization: Bearer <key>". The resolved empresa is exposed as
 * $request->attributes->get('idempresa'); controllers must scope every query to it.
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

        if (!$key->last_used_at || $key->last_used_at->lt(now()->subMinute())) {
            $key->forceFill(['last_used_at' => now()])->saveQuietly();
        }

        $request->attributes->set('idempresa', (string) $key->idempresa);
        $request->attributes->set('empresa_api_key', $key);

        return $next($request);
    }
}
