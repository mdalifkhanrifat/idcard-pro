<?php

namespace App\Http\Resources;

use App\Models\Tenant;
use App\Services\Notifications\NotificationComposer;
use App\Services\Notifications\NotificationSettings;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An organization's notification settings (always complete), what they can be set to, and each event's values
 * for a made-up occurrence in every language (`samples`, event => language => placeholder => value) so a page
 * can preview a text as it is typed.
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
            'samples' => app(NotificationComposer::class)->samples($this->resource),
        ];
    }
}
