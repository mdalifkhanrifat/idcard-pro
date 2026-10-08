<?php

namespace App\Models;

use App\Enums\TenantStatus;
use Database\Factories\TenantFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * @property int $id
 * @property string $public_id
 * @property string $name
 * @property string $slug
 * @property string|null $logo_path
 * @property array<string, mixed>|null $settings
 * @property TenantStatus $status
 */
#[Fillable([
    'name', 'slug', 'legal_name', 'email', 'phone', 'country_code', 'timezone',
    'locale', 'logo_path', 'primary_color', 'settings', 'status',
])]
class Tenant extends Model
{
    /** @use HasFactory<TenantFactory> */
    use HasFactory, HasUlids, LogsActivity, SoftDeletes;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'active',
        'timezone' => 'UTC',
        'locale' => 'en',
        'primary_color' => '#0F62FE',
    ];

    /**
     * @return array<int, string>
     */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'status' => TenantStatus::class,
        ];
    }

    /**
     * @return HasMany<User, $this>
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function isActive(): bool
    {
        return $this->status === TenantStatus::Active;
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'slug', 'legal_name', 'email', 'phone', 'timezone', 'locale', 'primary_color', 'status'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('tenant');
    }

    /**
     * Card number when a holder gets another card from a template with the same code: keep or new.
     */
    public function cardNumberOnReissue(): string
    {
        $value = $this->settings['card_number_on_reissue'] ?? null;

        return in_array($value, (array) config('cards.number_on_reissue'), true) ? $value : (string) config('cards.default_number_on_reissue');
    }

    /**
     * Card verification settings (3.7), with the configured defaults for anything not set and only the
     * details the public page may show.
     *
     * @return array{enabled: bool, fields: array<int, string>, show_revoke_reason: bool, link: string|null, note: string|null, log_checks: bool}
     */
    public function verificationSettings(): array
    {
        $defaults = (array) config('cards.verification.defaults');
        $saved = (array) ($this->settings['verification'] ?? []);
        $allowed = (array) config('cards.verification.fields');

        return [
            'enabled' => (bool) ($saved['enabled'] ?? $defaults['enabled']),
            // In the organization's order; details no longer offered are dropped
            'fields' => array_values(array_intersect((array) ($saved['fields'] ?? $defaults['fields']), $allowed)),
            'show_revoke_reason' => (bool) ($saved['show_revoke_reason'] ?? $defaults['show_revoke_reason']),
            'link' => filled($saved['link'] ?? null) ? (string) $saved['link'] : null,
            'note' => filled($saved['note'] ?? null) ? (string) $saved['note'] : null,
            'log_checks' => (bool) ($saved['log_checks'] ?? $defaults['log_checks']),
        ];
    }

    /**
     * The public verification link for a card code, or null while verification is off.
     */
    public function verifyUrl(?string $code): ?string
    {
        $settings = $this->verificationSettings();
        if (! $settings['enabled'] || blank($code)) {
            return null;
        }
        $format = $settings['link'] ?? rtrim((string) config('app.url'), '/').config('cards.verification.path');

        return str_replace('{code}', $code, $format);
    }

    /**
     * Default card size in mm, landscape (legacy "Template Setup"); CR80 unless the organization set one.
     *
     * @return array{width: float, height: float}
     */
    public function defaultCardSize(): array
    {
        $size = $this->settings['default_card_size'] ?? null;

        return is_array($size) ? ['width' => (float) $size['width'], 'height' => (float) $size['height']] : CardTemplate::CR80;
    }
}
