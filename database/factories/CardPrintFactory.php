<?php

namespace Database\Factories;

use App\Models\Card;
use App\Models\CardPrint;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CardPrint>
 */
class CardPrintFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'card_id' => Card::factory(),
            'method' => 'browser',
            'copies' => 1,
            'printed_at' => now(),
        ];
    }
}
