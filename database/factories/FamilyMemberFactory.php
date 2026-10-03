<?php

namespace Database\Factories;

use App\Enums\FamilyRelation;
use App\Models\Cardholder;
use App\Models\FamilyMember;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FamilyMember>
 */
class FamilyMemberFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'cardholder_id' => Cardholder::factory(),
            'relation' => fake()->randomElement(FamilyRelation::cases()),
            'name' => fake()->name(),
            'date_of_birth' => fake()->dateTimeBetween('-80 years', '-1 year')->format('Y-m-d'),
            'phone' => fake()->numerify('01#########'),
        ];
    }
}
