<?php

namespace App\Domain\Clients;

use App\Models\ClientCompany;
use Illuminate\Support\Facades\DB;

class ClientCompanyService
{
    /**
     * @param  array{
     *     name: string,
     *     description?: ?string,
     *     notes?: ?string,
     *     project_ids?: list<int|string>|null,
     * }  $data
     */
    public function create(array $data): ClientCompany
    {
        return DB::transaction(function () use ($data): ClientCompany {
            $company = ClientCompany::query()->create([
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'notes' => $data['notes'] ?? null,
            ]);

            if (array_key_exists('project_ids', $data)) {
                $this->syncProjects($company, $data['project_ids'] ?? []);
            }

            return $company->load(['projects', 'contacts']);
        });
    }

    /**
     * @param  array{
     *     name?: string,
     *     description?: ?string,
     *     notes?: ?string,
     *     project_ids?: list<int|string>|null,
     * }  $data
     */
    public function update(ClientCompany $company, array $data): ClientCompany
    {
        return DB::transaction(function () use ($company, $data): ClientCompany {
            if (array_key_exists('name', $data)) {
                $company->name = $data['name'];
            }
            if (array_key_exists('description', $data)) {
                $company->description = $data['description'];
            }
            if (array_key_exists('notes', $data)) {
                $company->notes = $data['notes'];
            }
            $company->save();

            if (array_key_exists('project_ids', $data)) {
                $this->syncProjects($company, $data['project_ids'] ?? []);
            }

            return $company->load(['projects', 'contacts']);
        });
    }

    public function delete(ClientCompany $company): void
    {
        $company->delete();
    }

    /**
     * @param  list<int|string>|null  $projectIds
     */
    public function syncProjects(ClientCompany $company, ?array $projectIds): void
    {
        $company->projects()->sync($this->normalizeIds($projectIds));
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
