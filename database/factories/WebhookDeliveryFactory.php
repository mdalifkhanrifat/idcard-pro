<?php

namespace Database\Factories;

use App\Models\Tenant;
use App\Models\Webhook;
use App\Models\WebhookDelivery;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WebhookDelivery>
 */
class WebhookDeliveryFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'webhook_id' => Webhook::factory(),
            'event' => 'cardholder.created',
            'payload' => json_encode(['id' => fake()->uuid(), 'event' => 'cardholder.created', 'occurred_at' => now()->toIso8601String(), 'tenant' => 'alpha', 'data' => (object) []]),
        ];
    }
}
