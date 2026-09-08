<?php

namespace Database\Factories;

use App\Models\ClientCompany;
use App\Models\ClientContact;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ClientContact>
 */
class ClientContactFactory extends Factory
{
    protected $model = ClientContact::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'client_company_id' => ClientCompany::factory(),
            'last_name' => fake()->lastName(),
            'first_name' => fake()->firstName(),
            'middle_name' => fake()->optional(0.7)->firstName(),
            'position' => fake()->optional()->jobTitle(),
            'emails' => [fake()->unique()->safeEmail()],
            'telegram' => fake()->optional()->userName(),
            'phone' => fake()->optional()->phoneNumber(),
            'notes' => fake()->optional()->sentence(),
        ];
    }
}
