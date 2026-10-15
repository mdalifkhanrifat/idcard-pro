<?php

namespace App\Http\Resources;

use App\Models\Tenant;
use App\Services\Notifications\NotificationSettings;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An organization's notification settings (always complete) and what they can be set to.
 *
 * @mixin Tenant
 */
class NotificationSettingsResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'notifications' => $this->resource->notificationSettings(),
            'options' => NotificationSettings::options(),
        ];
    }
}
