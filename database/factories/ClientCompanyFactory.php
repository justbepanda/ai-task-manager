<?php

namespace Database\Factories;

use App\Models\ClientCompany;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ClientCompany>
 */
class ClientCompanyFactory extends Factory
{
    protected $model = ClientCompany::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->company(),
            'description' => fake()->optional()->sentence(),
            'notes' => fake()->optional()->paragraph(),
        ];
    }
}
