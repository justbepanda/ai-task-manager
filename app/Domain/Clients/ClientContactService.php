<?php

namespace App\Domain\Clients;

use App\Models\ClientCompany;
use App\Models\ClientContact;
use Illuminate\Support\Facades\DB;

class ClientContactService
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
     *     project_ids?: list<int|string>|null,
     * }  $data
     */
    public function create(ClientCompany $company, array $data): ClientContact
    {
        return DB::transaction(function () use ($company, $data): ClientContact {
            $contact = ClientContact::query()->create([
                'client_company_id' => $company->id,
                'last_name' => $data['last_name'],
                'first_name' => $data['first_name'],
                'middle_name' => $data['middle_name'] ?? null,
                'position' => $data['position'] ?? null,
                'emails' => $this->normalizeList($data['emails'] ?? []),
                'telegram' => $data['telegram'] ?? null,
                'phone' => $data['phone'] ?? null,
                'notes' => $data['notes'] ?? null,
            ]);

            if (array_key_exists('project_ids', $data)) {
                $this->syncProjects($contact, $data['project_ids'] ?? []);
            }

            return $contact->load(['clientCompany', 'projects']);
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
     *     project_ids?: list<int|string>|null,
     * }  $data
     */
    public function update(ClientContact $contact, array $data): ClientContact
    {
        return DB::transaction(function () use ($contact, $data): ClientContact {
            if (array_key_exists('last_name', $data)) {
                $contact->last_name = $data['last_name'];
            }
            if (array_key_exists('first_name', $data)) {
                $contact->first_name = $data['first_name'];
            }
            if (array_key_exists('middle_name', $data)) {
                $contact->middle_name = $data['middle_name'];
            }
            if (array_key_exists('position', $data)) {
                $contact->position = $data['position'];
            }
            if (array_key_exists('emails', $data)) {
                $contact->emails = $this->normalizeList($data['emails']);
            }
            if (array_key_exists('telegram', $data)) {
                $contact->telegram = $data['telegram'];
            }
            if (array_key_exists('phone', $data)) {
                $contact->phone = $data['phone'];
            }
            if (array_key_exists('notes', $data)) {
                $contact->notes = $data['notes'];
            }
            $contact->save();

            if (array_key_exists('project_ids', $data)) {
                $this->syncProjects($contact, $data['project_ids'] ?? []);
            }

            return $contact->load(['clientCompany', 'projects']);
        });
    }

    public function delete(ClientContact $contact): void
    {
        $contact->delete();
    }

    /**
     * Sync contact↔projects; also attach the contact's company to the same projects
     * (without detaching existing company links when contact projects are removed).
     *
     * @param  list<int|string>|null  $projectIds
     */
    public function syncProjects(ClientContact $contact, ?array $projectIds): void
    {
        $ids = $this->normalizeIds($projectIds);

        $contact->projects()->sync($ids);

        if ($ids !== []) {
            $contact->clientCompany->projects()->syncWithoutDetaching($ids);
        }
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
