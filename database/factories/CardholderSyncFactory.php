<?php

namespace Database\Factories;

use App\Models\CardholderSync;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CardholderSync>
 */
class CardholderSyncFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'total' => 3,
            'created_count' => 1,
            'updated_count' => 1,
            'unchanged_count' => 0,
            'failed_count' => 1,
            'started_at' => now()->subSeconds(2),
            'finished_at' => now(),
        ];
    }
}
