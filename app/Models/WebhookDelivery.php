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
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * One thing an address was to be told (6.2). The id inside what is sent is what the receiver sees, the same on every
 * attempt and when sent again by hand, so a receiver can tell a repeat from something new. What the address answered
 * is not kept, only its status.
 *
 * @property int $id
 * @property string $public_id
 * @property int $tenant_id
 * @property int $webhook_id
 * @property int|null $resend_of_id the delivery this one sends again, by hand
 * @property string $event
 * @property string $payload the exact JSON sent and signed; decoded, an empty object would come back a list
 * @property WebhookDeliveryStatus $status
 * @property int $attempts
 * @property int|null $response_code
 * @property WebhookFailure|null $failure
 * @property Carbon|null $delivered_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Webhook $webhook
 * @property-read WebhookDelivery|null $resendOf
 * @property-read WebhookDelivery|null $resend
 */
#[Fillable(['public_id', 'webhook_id', 'resend_of_id', 'event', 'payload', 'status', 'attempts', 'response_code', 'failure', 'delivered_at'])]
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
     * @return BelongsTo<self, $this>
     */
    public function resendOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'resend_of_id');
    }

    /**
     * The delivery that sent this one again, if any (at most one: the next resend repeats that one).
     *
     * @return HasOne<self, $this>
     */
    public function resend(): HasOne
    {
        return $this->hasOne(self::class, 'resend_of_id');
    }

    /**
     * The id the receiver knows this by: a delivery sent again keeps the one in the text it repeats.
     */
    public function receiverId(): string
    {
        return (string) (json_decode($this->payload, true)['id'] ?? $this->public_id);
    }
}
