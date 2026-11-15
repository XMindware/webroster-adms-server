<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\Request;

/**
 * What may be logged about a device (/iclock/*) request (S3).
 *
 * Bodies carry fingerprint and face templates, and headers can carry
 * credentials, so neither is ever logged. Only an allowlist of protocol
 * query parameters is kept, each capped in length, plus the body size.
 */
final class DeviceRequestMetadata
{
    /** Protocol query parameters that describe a request without carrying data. */
    public const ALLOWED_KEYS = [
        'SN', 'table', 'tablename', 'type', 'options', 'pushver', 'language',
        'Stamp', 'OpStamp', 'PhotoStamp', 'count', 'cmdid', 'ID', 'Return', 'INFO',
    ];

    private const MAX_VALUE_LENGTH = 64;

    /**
     * @return array<string, mixed>
     */
    public static function fromRequest(Request $request): array
    {
        return [
            'method' => $request->getMethod(),
            'path' => '/'.ltrim($request->path(), '/'),
        ] + self::filter($request->query->all()) + [
            'bytes' => strlen((string) $request->getContent()),
        ];
    }

    /**
     * Keep only allowlisted keys with short scalar values.
     *
     * @param  array<mixed>  $values
     * @return array<string, string>
     */
    public static function filter(array $values): array
    {
        $kept = [];
        foreach (self::ALLOWED_KEYS as $key) {
            if (! array_key_exists($key, $values) || ! is_scalar($values[$key])) {
                continue;
            }
            $kept[$key] = mb_substr((string) $values[$key], 0, self::MAX_VALUE_LENGTH);
        }

        return $kept;
    }

    /**
     * Summarise a body without its contents.
     *
     * @return array{bytes: int, lines: int}
     */
    public static function bodySummary(string $body): array
    {
        $lines = preg_split('/\r\n|\r|\n/', trim($body)) ?: [];

        return [
            'bytes' => strlen($body),
            'lines' => trim($body) === '' ? 0 : count($lines),
        ];
    }
}
