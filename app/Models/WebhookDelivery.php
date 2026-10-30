<?php

namespace App\Models;

use App\Enums\WebhookDeliveryStatus;
use App\Enums\WebhookFailure;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\WebhookDeliveryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One thing an address was to be told (6.2). Its id is what the receiver sees, the same on every attempt, so a
 * receiver can tell a repeat from something new. What the address answered is not kept, only its status.
 *
 * @property int $id
 * @property string $public_id
 * @property int $tenant_id
 * @property int $webhook_id
 * @property string $event
 * @property array<string, mixed> $payload
 * @property WebhookDeliveryStatus $status
 * @property int $attempts
 * @property int|null $response_code
 * @property WebhookFailure|null $failure
 * @property Carbon|null $delivered_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Webhook $webhook
 */
#[Fillable(['public_id', 'webhook_id', 'event', 'payload', 'status', 'attempts', 'response_code', 'failure', 'delivered_at'])]
class WebhookDelivery extends Model
{
    /** @use HasFactory<WebhookDeliveryFactory> */
    use BelongsToTenant, HasFactory, HasUlids;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'queued',
        'attempts' => 0,
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
            'payload' => 'array',
            'status' => WebhookDeliveryStatus::class,
            'failure' => WebhookFailure::class,
            'delivered_at' => 'datetime',
        ];
    }

    /**
     * Even after the webhook is deleted, the log says where this went.
     *
     * @return BelongsTo<Webhook, $this>
     */
    public function webhook(): BelongsTo
    {
        return $this->belongsTo(Webhook::class)->withTrashed();
    }

    /**
     * The exact text sent and signed.
     */
    public function body(): string
    {
        return json_encode($this->payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
