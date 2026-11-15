<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

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
     * Create a key for an empresa. Returns [model, plaintext]; the plaintext is not recoverable later.
     *
     * @return array{0: self, 1: string}
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

    public static function findActive(string $plaintext): ?self
    {
        return static::where('key_hash', static::hashKey($plaintext))
            ->whereNull('revoked_at')
            ->first();
    }
}
