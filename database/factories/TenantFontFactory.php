<?php

namespace Database\Factories;

use App\Models\Tenant;
use App\Models\TenantFont;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<TenantFont>
 */
class TenantFontFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'family' => 'Licensed '.Str::title(fake()->unique()->word()),
            'weight' => 400,
            'style' => 'normal',
            'format' => 'truetype',
            'disk' => 'local',
            'path' => 'tenants/test/fonts/'.Str::lower((string) Str::ulid()).'.ttf',
            'original_name' => 'font.ttf',
            'size' => 1024,
        ];
    }
}
