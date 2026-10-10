<?php

namespace App\Observers;

use App\Models\Cardholder;
use App\Models\FamilyMember;
use App\Services\Biometrics\BiometricStatusService;

/**
 * A new person starts with the status the organization's settings give them (e.g. incomplete where
 * fingerprints are required), however they were added: form, import or a connected system.
 */
class BiometricStatusObserver
{
    public function __construct(private BiometricStatusService $statuses) {}

    public function created(Cardholder|FamilyMember $person): void
    {
        if ($person instanceof FamilyMember) {
            $this->statuses->refresh($person->cardholder, $person, collect());

            return;
        }
        $this->statuses->refresh($person, null, collect());
    }
}
