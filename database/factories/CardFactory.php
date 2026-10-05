<?php

namespace Database\Factories;

use App\Models\Card;
use App\Models\Cardholder;
use App\Models\CardTemplate;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Card>
 */
class CardFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $number = 'EMP/26'.fake()->unique()->numerify('######');

        return [
            'tenant_id' => Tenant::factory(),
            'cardholder_id' => Cardholder::factory(),
            'card_template_id' => CardTemplate::factory(),
            'template_version' => 1,
            'layout' => ['size_mm' => ['width' => 85.6, 'height' => 54], 'orientation' => 'landscape', 'is_double_sided' => false, 'elements' => []],
            'number' => $number,
            'reason' => 'new',
            'issue_date' => today(),
            'expiry_date' => today()->addYears(2),
            'status' => 'active',
            'active_number_key' => $number,
        ];
    }

    public function configure(): static
    {
        // Active cards hold the per-holder key the database uses for "one active card per holder"
        return $this->afterMaking(function (Card $card): void {
            if ($card->isActive()) {
                $card->active_holder_key ??= Card::holderKey($card->cardholder_id, $card->family_member_id);
            }
        });
    }
}
