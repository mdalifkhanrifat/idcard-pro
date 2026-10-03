<?php

namespace Database\Factories;

use App\Enums\CardholderStatus;
use App\Enums\EmploymentType;
use App\Enums\Gender;
use App\Models\Cardholder;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Cardholder>
 */
class CardholderFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'full_name' => fake()->name(),
            'date_of_birth' => fake()->dateTimeBetween('-60 years', '-18 years')->format('Y-m-d'),
            'gender' => fake()->randomElement(Gender::cases()),
            'employee_no' => fake()->unique()->numerify('EMP#####'),
            'personal_phone' => fake()->numerify('01#########'),
            'employment_type' => EmploymentType::Staff,
            'joining_date' => fake()->dateTimeBetween('-20 years', 'now')->format('Y-m-d'),
            'status' => CardholderStatus::Active,
        ];
    }

    public function status(CardholderStatus $status): static
    {
        return $this->state(fn (array $attributes) => ['status' => $status]);
    }
}
