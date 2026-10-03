<?php

namespace Database\Factories;

use App\Models\Cardholder;
use App\Models\CardholderFile;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<CardholderFile>
 */
class CardholderFileFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'cardholder_id' => Cardholder::factory(),
            'collection' => 'document',
            'title' => fake()->words(2, true),
            'disk' => config('cardholder_files.disk'),
            'path' => 'cardholders/'.Str::ulid().'.pdf',
            'original_name' => 'document.pdf',
            'mime_type' => 'application/pdf',
            'size' => 1024,
            'checksum' => hash('sha256', Str::random()),
        ];
    }
}
