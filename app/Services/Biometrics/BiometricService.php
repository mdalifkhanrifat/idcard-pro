<?php

namespace App\Services\Biometrics;

use App\Models\Cardholder;
use App\Models\FamilyMember;
use App\Repositories\Contracts\BiometricCaptureRepositoryInterface;
use Illuminate\Support\Collection;

/**
 * Biometric captures of cardholders and their family members (Phase 4). Records cascade with the
 * person; their encrypted files are removed here once the records are gone.
 */
class BiometricService
{
    public function __construct(
        private BiometricCaptureRepositoryInterface $captures,
        private BiometricVault $vault,
    ) {}

    /**
     * Where a cardholder's (and their family members') biometric files are; read before the cardholder is deleted.
     *
     * @return Collection<int, array{disk: string, path: string}>
     */
    public function storedLocations(Cardholder $cardholder): Collection
    {
        return $this->captures->locationsFor($cardholder);
    }

    /**
     * Where a family member's biometric files are; read before the member is deleted.
     *
     * @return Collection<int, array{disk: string, path: string}>
     */
    public function storedLocationsOfMember(FamilyMember $member): Collection
    {
        return $this->captures->locationsForMember($member);
    }

    /**
     * @param  Collection<int, array{disk: string, path: string}>  $locations
     */
    public function deleteStored(Collection $locations): void
    {
        $this->vault->delete($locations);
    }
}
