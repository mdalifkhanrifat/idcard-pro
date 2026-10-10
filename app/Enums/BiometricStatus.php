<?php

namespace App\Enums;

/**
 * Whether a person meets the organization's biometric settings, as kept on cardholders and family members.
 */
enum BiometricStatus: string
{
    // Every required kind is captured
    case Complete = 'complete';
    // Complete, but a required kind only because its positions could not all be captured (e.g. missing fingers)
    case Exceptions = 'exceptions';
    // A required kind still needs captures
    case Incomplete = 'incomplete';
    // Biometrics are off, nothing is required, or family members are not captured
    case NotRequired = 'not_required';

    /**
     * @param  array{applies: bool, complete: bool, modalities: array<string, array<string, mixed>>}  $evaluation  BiometricRequirement::evaluate()
     */
    public static function fromEvaluation(array $evaluation): self
    {
        $required = array_filter($evaluation['modalities'], fn (array $modality): bool => (bool) $modality['required']);
        if (! $evaluation['applies'] || $required === []) {
            return self::NotRequired;
        }
        if (! $evaluation['complete']) {
            return self::Incomplete;
        }

        return array_filter($required, fn (array $modality): bool => (bool) $modality['with_exceptions']) !== [] ? self::Exceptions : self::Complete;
    }

    /**
     * Whether the person still holds up a card where the organization requires biometrics.
     */
    public function blocks(): bool
    {
        return $this === self::Incomplete;
    }
}
