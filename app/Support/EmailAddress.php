<?php

namespace App\Support;

final class EmailAddress
{
    public static function extract(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);
        if ($value === '') {
            return null;
        }

        if (preg_match('/<([^>]+)>/', $value, $matches) === 1) {
            $email = strtolower(trim($matches[1]));

            return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
        }

        $email = strtolower($value);

        return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
    }

    /**
     * @param  list<string>|string|null  $values
     * @return list<string>
     */
    public static function extractMany(array|string|null $values): array
    {
        if ($values === null) {
            return [];
        }

        if (is_string($values)) {
            $values = [$values];
        }

        $emails = [];
        foreach ($values as $value) {
            if (! is_string($value)) {
                continue;
            }
            $email = self::extract($value);
            if ($email !== null) {
                $emails[] = $email;
            }
        }

        return array_values(array_unique($emails));
    }

    public static function domain(?string $emailOrAddress): ?string
    {
        $email = self::extract($emailOrAddress) ?? (is_string($emailOrAddress) ? strtolower(trim($emailOrAddress)) : null);
        if ($email === null || $email === '' || ! str_contains($email, '@')) {
            return null;
        }

        return substr($email, strrpos($email, '@') + 1) ?: null;
    }

    public static function normalizeList(?array $items): array
    {
        if ($items === null) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn ($item) => is_string($item) ? strtolower(trim($item)) : '',
            $items,
        )));
    }
}
