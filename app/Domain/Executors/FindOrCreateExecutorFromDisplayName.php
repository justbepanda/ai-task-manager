<?php

declare(strict_types=1);

namespace App\Domain\Executors;

use App\Models\Executor;
use App\Support\EmailAddress;

class FindOrCreateExecutorFromDisplayName
{
    /**
     * @return array{executor: Executor, created: bool}
     */
    public function handle(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            throw new \InvalidArgumentException('Empty executor display name');
        }

        $email = EmailAddress::extract($raw);
        if ($email !== null) {
            return $this->fromEmail($email, $raw);
        }

        return $this->fromPersonName($raw);
    }

    /**
     * @return array{executor: Executor, created: bool}
     */
    private function fromEmail(string $email, string $raw): array
    {
        $existing = Executor::query()->get()->first(function (Executor $executor) use ($email): bool {
            return in_array($email, EmailAddress::normalizeList($executor->emails ?? []), true);
        });

        if ($existing !== null) {
            return ['executor' => $existing, 'created' => false];
        }

        $local = explode('@', $email)[0] ?? 'user';
        $local = preg_replace('/[._+]+/', ' ', $local) ?: $local;
        $parts = $this->splitName(trim($local));

        $executor = Executor::query()->create([
            'last_name' => $parts['last_name'],
            'first_name' => $parts['first_name'],
            'middle_name' => $parts['middle_name'],
            'emails' => [$email],
            'notes' => 'Автосоздан из Redmine ('.$raw.')',
            'is_active' => true,
        ]);

        return ['executor' => $executor, 'created' => true];
    }

    /**
     * @return array{executor: Executor, created: bool}
     */
    private function fromPersonName(string $displayName): array
    {
        $existing = $this->findByNameParts($displayName);
        if ($existing !== null) {
            return ['executor' => $existing, 'created' => false];
        }

        $parts = $this->splitName($displayName);

        $executor = Executor::query()->create([
            'last_name' => $parts['last_name'],
            'first_name' => $parts['first_name'],
            'middle_name' => $parts['middle_name'],
            'emails' => [],
            'notes' => 'Автосоздан из Redmine ('.$displayName.')',
            'is_active' => true,
        ]);

        return ['executor' => $executor, 'created' => true];
    }

    private function findByNameParts(string $displayName): ?Executor
    {
        $needles = $this->normalizedTokens($displayName);
        if ($needles === []) {
            return null;
        }

        return Executor::query()->get()->first(function (Executor $executor) use ($needles): bool {
            $hay = $this->normalizedTokens(implode(' ', array_filter([
                $executor->last_name,
                $executor->first_name,
                $executor->middle_name,
            ])));

            if (count($hay) !== count($needles)) {
                return false;
            }

            sort($hay);
            $sortedNeedles = $needles;
            sort($sortedNeedles);

            return $hay === $sortedNeedles;
        });
    }

    /**
     * @return array{last_name: string, first_name: string, middle_name: ?string}
     */
    private function splitName(string $displayName): array
    {
        $tokens = preg_split('/\s+/u', trim($displayName)) ?: [];
        $tokens = array_values(array_filter(array_map('trim', $tokens), static fn (string $t): bool => $t !== ''));

        if ($tokens === []) {
            return ['last_name' => 'Unknown', 'first_name' => 'Unknown', 'middle_name' => null];
        }

        if (count($tokens) === 1) {
            return ['last_name' => $tokens[0], 'first_name' => $tokens[0], 'middle_name' => null];
        }

        if (count($tokens) === 2) {
            // Redmine UI usually "Имя Фамилия".
            return [
                'first_name' => $tokens[0],
                'last_name' => $tokens[1],
                'middle_name' => null,
            ];
        }

        // 3+ : "Фамилия Имя Отчество"
        return [
            'last_name' => $tokens[0],
            'first_name' => $tokens[1],
            'middle_name' => implode(' ', array_slice($tokens, 2)),
        ];
    }

    /**
     * @return list<string>
     */
    private function normalizedTokens(string $value): array
    {
        $tokens = preg_split('/\s+/u', mb_strtolower(trim($value))) ?: [];

        return array_values(array_filter(array_map('trim', $tokens), static fn (string $t): bool => $t !== ''));
    }
}
