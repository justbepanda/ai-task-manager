<?php

namespace App\Domain\Executors;

use App\Models\Executor;
use Illuminate\Support\Facades\DB;

class ExecutorService
{
    /**
     * @param  array{
     *     last_name: string,
     *     first_name: string,
     *     middle_name?: ?string,
     *     position?: ?string,
     *     emails?: list<string>|string|null,
     *     telegram?: ?string,
     *     phone?: ?string,
     *     notes?: ?string,
     *     is_active?: bool,
     *     project_ids?: list<int|string>|null,
     * }  $data
     */
    public function create(array $data): Executor
    {
        return DB::transaction(function () use ($data): Executor {
            $executor = Executor::query()->create([
                'last_name' => $data['last_name'],
                'first_name' => $data['first_name'],
                'middle_name' => $data['middle_name'] ?? null,
                'position' => $data['position'] ?? null,
                'emails' => $this->normalizeList($data['emails'] ?? []),
                'telegram' => $data['telegram'] ?? null,
                'phone' => $data['phone'] ?? null,
                'notes' => $data['notes'] ?? null,
                'is_active' => $data['is_active'] ?? true,
            ]);

            if (array_key_exists('project_ids', $data)) {
                $this->syncProjects($executor, $data['project_ids'] ?? []);
            }

            return $executor->load('projects');
        });
    }

    /**
     * @param  array{
     *     last_name?: string,
     *     first_name?: string,
     *     middle_name?: ?string,
     *     position?: ?string,
     *     emails?: list<string>|string|null,
     *     telegram?: ?string,
     *     phone?: ?string,
     *     notes?: ?string,
     *     is_active?: bool,
     *     project_ids?: list<int|string>|null,
     * }  $data
     */
    public function update(Executor $executor, array $data): Executor
    {
        return DB::transaction(function () use ($executor, $data): Executor {
            if (array_key_exists('last_name', $data)) {
                $executor->last_name = $data['last_name'];
            }
            if (array_key_exists('first_name', $data)) {
                $executor->first_name = $data['first_name'];
            }
            if (array_key_exists('middle_name', $data)) {
                $executor->middle_name = $data['middle_name'];
            }
            if (array_key_exists('position', $data)) {
                $executor->position = $data['position'];
            }
            if (array_key_exists('emails', $data)) {
                $executor->emails = $this->normalizeList($data['emails']);
            }
            if (array_key_exists('telegram', $data)) {
                $executor->telegram = $data['telegram'];
            }
            if (array_key_exists('phone', $data)) {
                $executor->phone = $data['phone'];
            }
            if (array_key_exists('notes', $data)) {
                $executor->notes = $data['notes'];
            }
            if (array_key_exists('is_active', $data)) {
                $executor->is_active = (bool) $data['is_active'];
            }
            $executor->save();

            if (array_key_exists('project_ids', $data)) {
                $this->syncProjects($executor, $data['project_ids'] ?? []);
            }

            return $executor->load('projects');
        });
    }

    public function delete(Executor $executor): void
    {
        $executor->delete();
    }

    /**
     * @param  list<int|string>|null  $projectIds
     */
    public function syncProjects(Executor $executor, ?array $projectIds): void
    {
        $executor->projects()->sync($this->normalizeIds($projectIds));
    }

    /**
     * @return list<string>
     */
    public function linesToList(?string $raw): array
    {
        if ($raw === null || trim($raw) === '') {
            return [];
        }

        $parts = preg_split('/[\n,]+/', $raw) ?: [];

        return array_values(array_filter(array_map(
            static fn (string $item): string => trim($item),
            $parts,
        ), static fn (string $item): bool => $item !== ''));
    }

    /**
     * @param  list<string>|null  $items
     */
    public function listToLines(?array $items): string
    {
        if ($items === null || $items === []) {
            return '';
        }

        return implode("\n", $items);
    }

    /**
     * @param  list<string>|string|null  $value
     * @return list<string>
     */
    private function normalizeList(array|string|null $value): array
    {
        if (is_array($value)) {
            return array_values(array_filter(array_map(
                static fn (mixed $item): string => trim((string) $item),
                $value,
            ), static fn (string $item): bool => $item !== ''));
        }

        return $this->linesToList($value);
    }

    /**
     * @param  list<int|string>|null  $ids
     * @return list<int>
     */
    private function normalizeIds(?array $ids): array
    {
        if ($ids === null || $ids === []) {
            return [];
        }

        return array_values(array_unique(array_map(
            static fn (mixed $id): int => (int) $id,
            array_filter($ids, static fn (mixed $id): bool => $id !== null && $id !== ''),
        )));
    }
}
