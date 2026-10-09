<?php

namespace App\Repositories\Contracts;

use App\Models\BiometricCapture;
use App\Models\Cardholder;
use App\Models\FamilyMember;
use Illuminate\Support\Collection;

/**
 * @extends RepositoryInterface<BiometricCapture>
 */
interface BiometricCaptureRepositoryInterface extends RepositoryInterface
{
    /**
     * Current captures of the cardholder, or of one of their family members when given.
     *
     * @return Collection<int, BiometricCapture>
     */
    public function currentFor(Cardholder $cardholder, ?FamilyMember $member = null): Collection;

    /**
     * Move the current capture of a position (if any) to history; returns how many were moved.
     */
    public function markReplaced(Cardholder $cardholder, ?FamilyMember $member, string $modality, string $position): int;

    /**
     * Stored files (images and templates) of a cardholder and their family members.
     *
     * @return Collection<int, array{disk: string, path: string}>
     */
    public function locationsFor(Cardholder $cardholder): Collection;

    /**
     * Stored files of one family member.
     *
     * @return Collection<int, array{disk: string, path: string}>
     */
    public function locationsForMember(FamilyMember $member): Collection;
}
