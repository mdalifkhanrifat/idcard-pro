<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Notifications\UpdateNotificationSettingsRequest;
use App\Http\Resources\NotificationSettingsResource;
use App\Services\Tenants\TenantService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Gate;

/**
 * The organization's notification settings on their own, for the notifications page and integrations; saved the
 * same way as the organization's settings (one audit entry per changed setting).
 */
class NotificationSettingsController extends Controller
{
    public function __construct(
        private TenantService $tenants,
        private TenantContext $tenantContext,
    ) {}

    /**
     * For those who see the sent messages or may change the settings.
     */
    public function show(): NotificationSettingsResource
    {
        abort_unless(Gate::any(['notifications.view', 'settings.update']), 403);

        return new NotificationSettingsResource($this->tenantContext->get());
    }

    public function update(UpdateNotificationSettingsRequest $request): NotificationSettingsResource
    {
        $tenant = $this->tenants->updateNotificationSettings($this->tenantContext->get(), (array) $request->validated('notifications'));

        return new NotificationSettingsResource($tenant);
    }
}
