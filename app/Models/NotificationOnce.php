<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A scheduled message already sent (5.7d), e.g. a reminder 30 days before a card's last day: the event, what it is
 * about and which one (`key`), so it goes once.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $event
 * @property int $subject_id the id of what the event is about (a card for card_expiring)
 * @property string $key
 * @property Carbon|null $created_at
 */
#[Table('notification_once')]
#[Fillable(['tenant_id', 'event', 'subject_id', 'key'])]
class NotificationOnce extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;
}
