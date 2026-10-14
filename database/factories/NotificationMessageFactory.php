<?php

namespace Database\Factories;

use App\Enums\NotificationStatus;
use App\Models\NotificationMessage;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NotificationMessage>
 */
class NotificationMessageFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'event' => 'card_ready',
            'channel' => 'mail',
            'address' => fake()->safeEmail(),
            'languages' => 'bn',
            'subject' => 'Your ID card is ready',
            'body' => fake()->sentence(),
            'status' => NotificationStatus::Queued,
        ];
    }

    public function sent(): static
    {
        return $this->state(['status' => NotificationStatus::Sent, 'attempts' => 1, 'driver' => 'array', 'sent_at' => now()]);
    }
}
