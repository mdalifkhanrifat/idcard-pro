<?php

namespace Database\Factories;

use App\Models\Tenant;
use App\Models\Webhook;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Webhook>
 */
class WebhookFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'name' => fake()->words(2, true),
            'url' => 'https://hr.example.com/hooks/'.fake()->uuid(),
            'events' => ['cardholder.created'],
            'secret' => 'whsec_'.fake()->sha256(),
            'enabled' => true,
        ];
    }
}
