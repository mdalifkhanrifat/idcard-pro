<?php

namespace Database\Factories;

use App\Models\Tenant;
use App\Models\TenantImage;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<TenantImage>
 */
class TenantImageFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'name' => Str::title(fake()->words(2, true)).' logo',
            'disk' => 'local',
            'path' => 'tenants/test/library/'.Str::lower((string) Str::ulid()).'.png',
            'mime_type' => 'image/png',
            'width' => 400,
            'height' => 400,
            'size' => 2048,
        ];
    }
}
