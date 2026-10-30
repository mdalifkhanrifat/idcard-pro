<?php

namespace App\Services\Webhooks;

use App\Http\Resources\AppointmentResource;
use App\Http\Resources\CardholderResource;
use App\Http\Resources\CardResource;
use App\Models\Appointment;
use App\Models\Card;
use App\Models\Cardholder;
use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Traits\Localizable;

/**
 * What a webhook says about a record: the API's own description of it, as saved, the same whoever caused the change
 * and in whatever language they read: no reader, so nothing that depends on one, and in the organization's language.
 * Only the relations named here are loaded, so no photo, signature, document or biometric ever goes out.
 */
class WebhookPayloads
{
    use Localizable;

    /**
     * @return array<string, mixed>
     */
    public function of(Appointment|Card|Cardholder $subject, Tenant $tenant): array
    {
        [$resource, $relations] = match (true) {
            $subject instanceof Cardholder => [CardholderResource::class, Cardholder::lookupRelations()],
            $subject instanceof Card => [CardResource::class, ['template', 'familyMember', 'cardholder.department', 'replacedBy']],
            $subject instanceof Appointment => [AppointmentResource::class, ['location', 'cardholder', 'familyMember']],
        };
        // Saved values rather than the caller's copy; a record deleted for good has only that copy
        $saved = $subject->fresh($relations) ?? $subject->withoutRelations()->load($relations);

        return $this->withLocale((string) $tenant->locale, fn (): array => (new $resource($saved))->resolve(new Request));
    }
}
