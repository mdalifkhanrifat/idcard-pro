<?php

namespace App\Services\Cards\Requirements;

use App\Models\Cardholder;
use App\Models\FamilyMember;
use App\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use WeakMap;

/**
 * Complete biometrics before the moments the organization chose (settings.biometrics.required_before).
 * Reads the person's kept status, so whole lists and batches are checked without loading captures.
 * Someone whose positions are all marked unavailable is complete with exceptions and never held up.
 */
class BiometricCardRequirement implements CardRequirement
{
    /**
     * The organization's moments, worked out once per loaded organization (a new request loads it again).
     *
     * @var WeakMap<Tenant, array<int, string>>
     */
    private WeakMap $moments;

    public function __construct(private TenantContext $tenantContext)
    {
        $this->moments = new WeakMap;
    }

    public function problems(Cardholder $cardholder, ?FamilyMember $member, string $moment): array
    {
        $tenant = $this->tenantContext->get();
        if ($tenant === null) {
            return [];
        }
        $this->moments[$tenant] ??= $tenant->biometricSettings()['required_before'];
        $person = $member ?? $cardholder;
        if (! in_array($moment, $this->moments[$tenant], true) || ! $person->biometric_status->blocks()) {
            return [];
        }

        return [[
            'code' => 'biometrics',
            'message' => __('cards.requirements.biometrics', ['name' => $member !== null ? $member->name : $cardholder->full_name]),
            'details' => [
                'status' => $person->biometric_status->value,
                // Where to complete it (the cardholder's Biometrics tab, for the family member when set)
                'cardholder' => $cardholder->public_id,
                'family_member' => $member?->public_id,
            ],
        ]];
    }
}
