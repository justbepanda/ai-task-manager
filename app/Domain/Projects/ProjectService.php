<?php

namespace App\Domain\Projects;

use App\Enums\ProjectStatus;
use App\Models\Project;
use Illuminate\Support\Facades\DB;

class ProjectService
{
    /**
     * @param  array{
     *     name: string,
     *     description?: ?string,
     *     status: ProjectStatus|string,
     *     manager_executor_id?: int|string|null,
     *     instruction_body?: ?string,
     *     client_emails?: list<string>|string|null,
     *     staff_emails?: list<string>|string|null,
     *     domains?: list<string>|string|null,
     *     subject_patterns?: list<string>|string|null,
     *     keywords?: list<string>|string|null,
     *     technical_sources?: list<string>|string|null,
     *     custom_rules?: list<string>|string|null,
     * }  $data
     */
    public function create(array $data): Project
    {
        return DB::transaction(function () use ($data): Project {
            $project = Project::query()->create([
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'status' => $data['status'],
                'manager_executor_id' => $this->nullableId($data['manager_executor_id'] ?? null),
            ]);

            $this->syncInstruction($project, $data['instruction_body'] ?? null);
            $this->syncMatchingRules($project, $data);

            return $project->load(['instruction', 'matchingRule', 'managerExecutor']);
        });
    }

    /**
     * @param  array{
     *     name?: string,
     *     description?: ?string,
     *     status?: ProjectStatus|string,
     *     manager_executor_id?: int|string|null,
     *     instruction_body?: ?string,
     *     client_emails?: list<string>|string|null,
     *     staff_emails?: list<string>|string|null,
     *     domains?: list<string>|string|null,
     *     subject_patterns?: list<string>|string|null,
     *     keywords?: list<string>|string|null,
     *     technical_sources?: list<string>|string|null,
     *     custom_rules?: list<string>|string|null,
     * }  $data
     */
    public function update(Project $project, array $data): Project
    {
        return DB::transaction(function () use ($project, $data): Project {
            if (array_key_exists('name', $data)) {
                $project->name = $data['name'];
            }
            if (array_key_exists('description', $data)) {
                $project->description = $data['description'];
            }
            if (array_key_exists('status', $data)) {
                $project->status = $data['status'];
            }
            if (array_key_exists('manager_executor_id', $data)) {
                $project->manager_executor_id = $this->nullableId($data['manager_executor_id']);
            }
            $project->save();

            if (array_key_exists('instruction_body', $data)) {
                $this->syncInstruction($project, $data['instruction_body']);
            }

            $ruleKeys = [
                'client_emails',
                'staff_emails',
                'domains',
                'subject_patterns',
                'keywords',
                'technical_sources',
                'custom_rules',
            ];

            if (collect($ruleKeys)->contains(fn (string $key): bool => array_key_exists($key, $data))) {
                $this->syncMatchingRules($project, $data);
            }

            return $project->load(['instruction', 'matchingRule', 'managerExecutor']);
        });
    }

    public function delete(Project $project): void
    {
        $project->delete();
    }

    private function nullableId(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $id = (int) $value;

        return $id > 0 ? $id : null;
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

    private function syncInstruction(Project $project, ?string $body): void
    {
        $body = trim((string) $body);

        if ($body === '') {
            $project->instruction()->delete();

            return;
        }

        $project->instruction()->updateOrCreate([], ['body' => $body]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function syncMatchingRules(Project $project, array $data): void
    {
        $normalized = [
            'client_emails' => $this->normalizeList($data['client_emails'] ?? []),
            'staff_emails' => $this->normalizeList($data['staff_emails'] ?? []),
            'domains' => $this->normalizeList($data['domains'] ?? []),
            'subject_patterns' => $this->normalizeList($data['subject_patterns'] ?? []),
            'keywords' => $this->normalizeList($data['keywords'] ?? []),
            'technical_sources' => $this->normalizeList($data['technical_sources'] ?? []),
            'custom_rules' => $this->normalizeList($data['custom_rules'] ?? []),
        ];

        $hasAny = collect($normalized)->contains(fn (array $items): bool => $items !== []);

        if (! $hasAny) {
            $project->matchingRule()->delete();

            return;
        }

        $project->matchingRule()->updateOrCreate([], $normalized);
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
}
