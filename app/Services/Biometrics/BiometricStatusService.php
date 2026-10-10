<?php

namespace App\Services\Biometrics;

use App\Enums\BiometricStatus;
use App\Models\BiometricCapture;
use App\Models\Cardholder;
use App\Models\FamilyMember;
use App\Models\Tenant;
use App\Repositories\Contracts\BiometricCaptureRepositoryInterface;
use App\Repositories\Contracts\CardholderRepositoryInterface;
use App\Repositories\Contracts\FamilyMemberRepositoryInterface;
use Illuminate\Support\Collection;

/**
 * Keeps each person's biometric status (cardholders.biometric_status, family_members.biometric_status)
 * in line with their captures and the organization's settings, so lists, card checks, reports and
 * connected systems read one stored value. Refreshed when captures change, when a person is added
 * (any way: form, import, sync) and for everybody when the biometric settings change.
 */
class BiometricStatusService
{
    public function __construct(
        private BiometricCaptureRepositoryInterface $captures,
        private CardholderRepositoryInterface $cardholders,
        private FamilyMemberRepositoryInterface $familyMembers,
    ) {}

    /**
     * Work out and keep one person's status.
     *
     * @param  Collection<int, BiometricCapture>|null  $captures  current captures, when already known (none for a new person)
     */
    public function refresh(Cardholder $cardholder, ?FamilyMember $member = null, ?Collection $captures = null): BiometricStatus
    {
        $status = $this->statusFor($this->settingsOf($cardholder->tenant), $captures ?? $this->captures->currentFor($cardholder, $member), $member !== null);
        if ($member !== null) {
            $this->familyMembers->setBiometricStatus([$member->id], $status);
            $member->setAttribute('biometric_status', $status->value)->syncOriginalAttribute('biometric_status');
        } else {
            $this->cardholders->setBiometricStatus([$cardholder->id], $status);
            $cardholder->setAttribute('biometric_status', $status->value)->syncOriginalAttribute('biometric_status');
        }

        return $status;
    }

    /**
     * Work out everybody's status in the current organization (after its settings changed).
     *
     * @return int how many people were checked
     */
    public function refreshAll(Tenant $tenant): int
    {
        $settings = $this->settingsOf($tenant);
        $checked = 0;
        $this->cardholders->eachBatch(200, function (Collection $batch) use ($settings, &$checked): void {
            $captures = $this->captures->currentForCardholders($batch->pluck('id')->all())
                ->groupBy(fn (BiometricCapture $capture): string => $capture->cardholder_id.':'.($capture->family_member_id ?? ''));
            $holders = [];
            $members = [];
            foreach ($batch as $cardholder) {
                $holders[$this->statusFor($settings, $captures->get($cardholder->id.':', collect()), false)->value][] = $cardholder->id;
                foreach ($cardholder->familyMembers as $member) {
                    $members[$this->statusFor($settings, $captures->get($cardholder->id.':'.$member->id, collect()), true)->value][] = $member->id;
                }
                $checked += 1 + $cardholder->familyMembers->count();
            }
            foreach ($holders as $status => $ids) {
                $this->cardholders->setBiometricStatus($ids, BiometricStatus::from($status));
            }
            foreach ($members as $status => $ids) {
                $this->familyMembers->setBiometricStatus($ids, BiometricStatus::from($status));
            }
        });

        return $checked;
    }

    /**
     * @param  array<string, mixed>  $settings
     * @param  Collection<int, BiometricCapture>  $captures
     */
    private function statusFor(array $settings, Collection $captures, bool $familyMember): BiometricStatus
    {
        /** @var array{enabled: bool, modalities: array<string, array{mode: string, count: int, positions: array<int, string>, substitutes: bool}>, family_members: bool} $settings */
        return BiometricStatus::fromEvaluation(BiometricRequirement::evaluate($settings, $captures, $familyMember));
    }

    /**
     * @return array<string, mixed>
     */
    private function settingsOf(Tenant $tenant): array
    {
        return $tenant->biometricSettings();
    }
}
