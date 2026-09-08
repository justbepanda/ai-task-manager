<?php

namespace Database\Factories;

use App\Enums\ProjectStatus;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Project>
 */
class ProjectFactory extends Factory
{
    protected $model = Project::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->company().' Project',
            'description' => fake()->optional()->paragraph(),
            'status' => ProjectStatus::Active,
        ];
    }

    public function paused(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ProjectStatus::Paused,
        ]);
    }

    public function archived(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ProjectStatus::Archived,
        ]);
    }
}
