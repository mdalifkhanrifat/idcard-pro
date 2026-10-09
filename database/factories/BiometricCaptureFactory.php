<?php

namespace Database\Factories;

use App\Models\BiometricCapture;
use App\Models\Cardholder;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Capture records without stored files; tests that need the encrypted files use BiometricVault.
 *
 * @extends Factory<BiometricCapture>
 */
class BiometricCaptureFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'cardholder_id' => Cardholder::factory(),
            'modality' => 'fingerprint',
            'position' => 'right_thumb',
            'status' => BiometricCapture::STATUS_CAPTURED,
            'driver' => 'simulator',
            'quality' => fake()->numberBetween(40, 100),
            'captured_at' => now(),
        ];
    }

    public function unavailable(string $reason = 'missing'): static
    {
        return $this->state(fn () => ['status' => BiometricCapture::STATUS_UNAVAILABLE, 'unavailable_reason' => $reason, 'driver' => null, 'quality' => null]);
    }
}
