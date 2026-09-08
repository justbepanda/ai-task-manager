<?php

namespace App\Support;

use Illuminate\Support\Str;

class SensitiveDataSanitizer
{
    private const SECRET_KEYS = [
        'password',
        'passwd',
        'secret',
        'api_key',
        'apikey',
        'token',
        'access_token',
        'refresh_token',
        'authorization',
        'auth',
        'credential',
        'credentials',
        'yandex_imap_password',
        'imap_password',
        'private_key',
    ];

    private const MAX_STRING = 500;

    private const MAX_JSON = 2000;

    /**
     * @param  array<string, mixed>|null  $details
     * @return array<string, mixed>|null
     */
    public static function sanitizeDetails(?array $details): ?array
    {
        if ($details === null) {
            return null;
        }

        return self::walk($details);
    }

    /**
     * Truncated sanitized JSON for AI debug logs (no API keys).
     *
     * @param  array<string, mixed>  $payload
     */
    public static function truncateJson(array $payload, int $max = self::MAX_JSON): string
    {
        $clean = self::walk($payload);
        $json = json_encode($clean, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if (! is_string($json)) {
            return '{}';
        }

        return Str::limit($json, $max, '…');
    }

    /**
     * Short human-readable summary for Orchid tables.
     *
     * @param  array<string, mixed>|null  $details
     */
    public static function summary(?array $details, int $limit = 120): string
    {
        if ($details === null || $details === []) {
            return '—';
        }

        $clean = self::walk($details);
        $parts = [];

        foreach ($clean as $key => $value) {
            if (is_scalar($value) || $value === null) {
                $parts[] = $key.'='.(string) ($value ?? 'null');
            } elseif (is_array($value)) {
                $parts[] = $key.'=['.count($value).']';
            }
            if (count($parts) >= 6) {
                break;
            }
        }

        return Str::limit(implode(', ', $parts), $limit);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function walk(array $data): array
    {
        $out = [];

        foreach ($data as $key => $value) {
            $keyStr = is_string($key) ? $key : (string) $key;

            if (self::isSecretKey($keyStr)) {
                $out[$keyStr] = '[redacted]';

                continue;
            }

            if (is_array($value)) {
                $out[$keyStr] = self::walk($value);

                continue;
            }

            if (is_string($value)) {
                $out[$keyStr] = Str::limit($value, self::MAX_STRING, '…');

                continue;
            }

            $out[$keyStr] = $value;
        }

        return $out;
    }

    private static function isSecretKey(string $key): bool
    {
        $normalized = strtolower(str_replace(['-', ' '], '_', $key));

        foreach (self::SECRET_KEYS as $secret) {
            if ($normalized === $secret || str_contains($normalized, $secret)) {
                return true;
            }
        }

        return false;
    }
}
