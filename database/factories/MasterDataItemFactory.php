<?php

namespace Database\Factories;

use App\Models\MasterDataItem;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MasterDataItem>
 */
class MasterDataItemFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'type' => 'department',
            'name' => fake()->unique()->words(2, true),
            'code' => strtoupper(fake()->unique()->bothify('???##')),
            'sort_order' => 0,
            'is_active' => true,
        ];
    }

    public function ofType(string $type): static
    {
        return $this->state(fn (array $attributes) => ['type' => $type]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => ['is_active' => false]);
    }
}
