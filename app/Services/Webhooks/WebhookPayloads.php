<?php

namespace App\Services\Webhooks;

use App\Http\Resources\AppointmentResource;
use App\Http\Resources\CardholderResource;
use App\Http\Resources\CardResource;
use App\Models\Appointment;
use App\Models\Card;
use App\Models\Cardholder;
use App\Models\CardholderImport;
use App\Models\CardholderSync;
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

    /**
     * What a file did, without what is in it: the ids say which records to read. `cardholders` is null when a run was
     * cut off and they are not known: the receiver then compares for itself.
     *
     * @param  array{created: array<int, string>, updated: array<int, string>}|null  $changed
     * @return array<string, mixed>
     */
    public function ofImport(CardholderImport $import, ?array $changed): array
    {
        return [
            'id' => $import->public_id,
            'status' => $import->status->value,
            'created' => $changed === null ? null : count($changed['created']),
            'updated' => $changed === null ? null : count($changed['updated']),
            'failed' => $import->failed_count,
            'cards' => $import->summary['cards_result'] ?? null,
            'started_at' => $import->started_at?->utc()->toIso8601ZuluString(),
            'finished_at' => $import->finished_at?->utc()->toIso8601ZuluString(),
            'cardholders' => $changed,
        ];
    }

    /**
     * What one request of an HR sync did, without what was sent: the ids say which records to read. A run that broke
     * off has no finished_at, and the ids of the records saved until then.
     *
     * @param  array{created: array<int, string>, updated: array<int, string>}  $changed
     * @return array<string, mixed>
     */
    public function ofSync(CardholderSync $sync, array $changed): array
    {
        return [
            'id' => $sync->public_id,
            'kind' => $sync->kind->value,
            'total' => $sync->total,
            'created' => count($changed['created']),
            'updated' => count($changed['updated']),
            'unchanged' => $sync->unchanged_count,
            'failed' => $sync->failed_count,
            'not_found' => $sync->not_found_count,
            'started_at' => $sync->started_at->utc()->toIso8601ZuluString(),
            'finished_at' => $sync->finished_at?->utc()->toIso8601ZuluString(),
            'cardholders' => $changed,
        ];
    }
}
