<?php

namespace Database\Factories;

use App\Models\Executor;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Executor>
 */
class ExecutorFactory extends Factory
{
    protected $model = Executor::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'last_name' => fake()->lastName(),
            'first_name' => fake()->firstName(),
            'middle_name' => fake()->optional(0.7)->firstName(),
            'position' => fake()->optional()->jobTitle(),
            'emails' => [fake()->unique()->companyEmail()],
            'telegram' => fake()->optional()->userName(),
            'phone' => fake()->optional()->phoneNumber(),
            'notes' => fake()->optional()->sentence(),
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}
