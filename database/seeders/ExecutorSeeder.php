<?php

namespace Database\Seeders;

use App\Domain\Executors\ExecutorService;
use App\Models\Executor;
use App\Models\Project;
use Illuminate\Database\Seeder;

class ExecutorSeeder extends Seeder
{
    public function run(): void
    {
        $service = app(ExecutorService::class);

        $shop = Project::query()->where('name', 'Интернет-магазин А')->first();
        $website = Project::query()->where('name', 'Корпоративный сайт')->first();
        $support = Project::query()->where('name', 'Redmine / техподдержка')->first();

        $this->seedExecutor($service, 'Смирнов', 'Алексей', 'Игоревич', 'Backend-разработчик', [
            'dev@agency.local',
        ], array_filter([$shop?->id, $support?->id]));

        $this->seedExecutor($service, 'Орлова', 'Елена', 'Дмитриевна', 'Frontend-разработчик', [
            'frontend@agency.local',
        ], array_filter([$website?->id, $shop?->id]));

        $this->seedExecutor($service, 'Васильев', 'Никита', null, 'Project manager', [
            'pm@agency.local',
            'support@agency.local',
        ], array_filter([$shop?->id, $website?->id, $support?->id]));
    }

    /**
     * @param  list<string>  $emails
     * @param  list<int>  $projectIds
     */
    private function seedExecutor(
        ExecutorService $service,
        string $lastName,
        string $firstName,
        ?string $middleName,
        string $position,
        array $emails,
        array $projectIds,
    ): void {
        $existing = Executor::query()
            ->where('last_name', $lastName)
            ->where('first_name', $firstName)
            ->first();

        $payload = [
            'last_name' => $lastName,
            'first_name' => $firstName,
            'middle_name' => $middleName,
            'position' => $position,
            'emails' => $emails,
            'telegram' => null,
            'phone' => null,
            'notes' => null,
            'is_active' => true,
            'project_ids' => $projectIds,
        ];

        if ($existing !== null) {
            $service->update($existing, $payload);
        } else {
            $service->create($payload);
        }
    }
}
