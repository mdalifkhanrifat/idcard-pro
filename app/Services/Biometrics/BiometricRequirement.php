<?php

namespace App\Services\Biometrics;

use App\Models\BiometricCapture;
use Illuminate\Support\Collection;

/**
 * Whether a person's current captures meet the organization's biometric settings. One rule for the
 * enrollment screen, the card issue and print checks (4.9) and connected systems:
 *
 * - a modality is done when it has as many captured positions as the settings ask for; with stand-ins
 *   allowed any position of the modality counts, otherwise only the chosen ones;
 * - when every position that could count is captured or marked unavailable (e.g. no fingers), the
 *   modality is complete with exceptions, so nobody is blocked for what cannot be captured;
 * - only required modalities decide whether the person is complete; off and optional ones never block.
 */
class BiometricRequirement
{
    /**
     * @param  array{enabled: bool, modalities: array<string, array{mode: string, count: int, positions: array<int, string>, substitutes: bool}>, family_members: bool}  $settings
     * @param  Collection<int, BiometricCapture>  $captures  current captures of the person
     * @return array{enabled: bool, applies: bool, complete: bool, modalities: array<string, array<string, mixed>>}
     */
    public static function evaluate(array $settings, Collection $captures, bool $familyMember): array
    {
        $applies = $settings['enabled'] && (! $familyMember || $settings['family_members']);
        $modalities = [];
        if ($applies) {
            foreach ($settings['modalities'] as $name => $modality) {
                if ($modality['mode'] !== 'off') {
                    $modalities[$name] = self::modality($name, $modality, $captures->where('modality', $name));
                }
            }
        }
        $missing = array_filter($modalities, fn (array $modality): bool => $modality['required'] && ! $modality['complete']);

        return [
            'enabled' => $settings['enabled'],
            'applies' => $applies,
            'complete' => $missing === [],
            'modalities' => $modalities,
        ];
    }

    /**
     * @param  array{mode: string, count: int, positions: array<int, string>, substitutes: bool}  $settings
     * @param  Collection<int, BiometricCapture>  $captures
     * @return array{mode: string, required: bool, needed: int, captured: array<int, string>, unavailable: array<int, string>, counted: int, complete: bool, with_exceptions: bool, next: array<int, string>}
     */
    private static function modality(string $name, array $settings, Collection $captures): array
    {
        $offered = array_keys((array) config("biometrics.modalities.{$name}.positions"));
        // Preferred positions first, then (with stand-ins) the rest in registry order
        $candidates = $settings['substitutes']
            ? array_values(array_unique([...$settings['positions'], ...$offered]))
            : $settings['positions'];
        $captured = $captures->filter(fn (BiometricCapture $capture): bool => $capture->isCaptured())->pluck('position')->values()->all();
        $unavailable = $captures->reject(fn (BiometricCapture $capture): bool => $capture->isCaptured())->pluck('position')->values()->all();
        $counted = count(array_intersect($candidates, $captured));
        $open = array_values(array_diff($candidates, $captured, $unavailable));
        $done = $counted >= $settings['count'];

        return [
            'mode' => $settings['mode'],
            'required' => $settings['mode'] === 'required',
            'needed' => $settings['count'],
            'captured' => $captured,
            'unavailable' => $unavailable,
            'counted' => $counted,
            'complete' => $done || $open === [],
            'with_exceptions' => ! $done && $open === [],
            // What to capture next, as many as are still needed
            'next' => $done ? [] : array_slice($open, 0, $settings['count'] - $counted),
        ];
    }
}
