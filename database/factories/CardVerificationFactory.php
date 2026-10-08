<?php

namespace Database\Factories;

use App\Models\Card;
use App\Models\CardVerification;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CardVerification>
 */
class CardVerificationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'card_id' => Card::factory(),
            'tenant_id' => fn (array $attributes) => Card::query()->withoutGlobalScopes()->find($attributes['card_id'])?->tenant_id,
            'status' => 'valid',
            'device' => fake()->regexify('[a-f0-9]{16}'),
            'verified_at' => now(),
        ];
    }
}
