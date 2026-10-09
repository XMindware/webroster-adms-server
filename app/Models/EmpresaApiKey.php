<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Machine-to-machine credential for the read-only device status API that
 * WebRoster polls.
 *
 * Only the sha256 of the key is stored, so a dump of this table does not hand
 * out access to every empresa's fleet; the plaintext is printed once by
 * `empresa-keys:create` and cannot be recovered afterwards. A key belongs to
 * exactly one idempresa and the middleware puts that value on the request, so
 * no endpoint ever has to trust an idempresa sent by the caller.
 */
class EmpresaApiKey extends Model
{
    protected $table = 'empresa_api_keys';

    protected $fillable = [
        'idempresa',
        'name',
        'key_hash',
        'last_used_at',
        'revoked_at',
    ];

    /**
     * Kept out of JSON responses - the whole point of storing the key hashed
     * is that reading the API cannot leak it back.
     */
    protected $hidden = ['key_hash'];

    protected $casts = [
        'last_used_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public static function hashKey(string $plaintext): string
    {
        return hash('sha256', $plaintext);
    }

    /**
     * Issue a key for an empresa.
     *
     * @return array{0: self, 1: string} the model and the plaintext key, which
     *                                   is not recoverable from the model
     */
    public static function issue(string $idempresa, ?string $name = null): array
    {
        $plaintext = 'wrk_' . Str::random(48);

        $key = static::create([
            'idempresa' => $idempresa,
            'name' => $name,
            'key_hash' => static::hashKey($plaintext),
        ]);

        return [$key, $plaintext];
    }

    /**
     * The key behind a plaintext token, or null when unknown or revoked.
     *
     * Revoked keys are filtered here rather than in the middleware so that
     * revoking is immediate for every caller, including a WebRoster instance
     * that already holds the token.
     */
    public static function findActive(string $plaintext): ?self
    {
        return static::where('key_hash', static::hashKey($plaintext))
            ->whereNull('revoked_at')
            ->first();
    }
}
