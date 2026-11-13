<?php

namespace App\Models;

use App\Models\Concerns\AuditsChanges;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\WebhookFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Support\LogOptions;

/**
 * An address an organization wants told about chosen events (6.2).
 *
 * @property int $id
 * @property string $public_id
 * @property int $tenant_id
 * @property string $name
 * @property string $url
 * @property array<int, string> $events
 * @property string $secret
 * @property Carbon|null $secret_rotated_at
 * @property bool $enabled
 * @property int $failure_count deliveries failed in a row
 * @property Carbon|null $disabled_at when it turned itself off
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'url', 'events', 'secret', 'enabled', 'failure_count', 'disabled_at'])]
#[Hidden(['secret'])]
class Webhook extends Model
{
    /** @use HasFactory<WebhookFactory> */
    use AuditsChanges, BelongsToTenant, HasFactory, HasUlids, SoftDeletes;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'enabled' => true,
        'failure_count' => 0,
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
     * @return HasMany<WebhookDelivery, $this>
     */
    public function deliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'events' => 'array',
            'secret' => 'encrypted',
            'secret_rotated_at' => 'datetime',
            'enabled' => 'boolean',
            'disabled_at' => 'datetime',
        ];
    }

    /**
     * The secret itself is never logged: only that it was changed, and when.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnly(['secret_rotated_at'])
            ->logExcept(['secret', 'failure_count', 'disabled_at'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('webhooks');
    }
}
