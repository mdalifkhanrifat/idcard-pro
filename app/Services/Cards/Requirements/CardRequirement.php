<?php

namespace App\Services\Cards\Requirements;

use App\Models\Cardholder;
use App\Models\FamilyMember;

/**
 * Something a person must meet before a card is issued or printed for them, decided by the
 * organization's own settings (e.g. complete biometrics). Listed in config/cards.php `requirements`,
 * so a new one (a photo, a signature, an HR approval) is a class there, used by every way of issuing
 * and printing: the screens, the API, batches and connected systems.
 */
interface CardRequirement
{
    /**
     * What is missing for the person (a family member for their own card) at this moment ("issue", "print").
     *
     * @return array<int, array{code: string, message: string, details: array<string, mixed>}>
     */
    public function problems(Cardholder $cardholder, ?FamilyMember $member, string $moment): array;
}
