<?php

namespace App\Support\Biometrics;

/**
 * The `field` of a card designer `biometric` element: biometric.<modality>.<position>, or
 * biometric.<modality>.auto for the first one captured in the organization's order of preference.
 * Modality and position names come from the registry (config/biometrics.php).
 */
final class BiometricCardField
{
    public const PREFIX = 'biometric.';

    public const AUTO = 'auto';

    public static function key(string $modality, string $position): string
    {
        return self::PREFIX.$modality.'.'.$position;
    }

    /**
     * @return array{modality: string, position: string}|null null when the value is not a biometric field
     */
    public static function parse(?string $field): ?array
    {
        if ($field === null || ! preg_match('/^biometric\.([a-z0-9_]+)\.([a-z0-9_]+)$/', $field, $match)) {
            return null;
        }

        return ['modality' => $match[1], 'position' => $match[2]];
    }

    /**
     * Field keys a designer may use for the printable modalities (BiometricSettings::printable()), `auto` first.
     *
     * @param  array<string, array<int, string>>  $printable
     * @return array<string, array<int, string>>
     */
    public static function options(array $printable): array
    {
        return collect($printable)
            ->map(fn (array $positions, string $modality): array => array_map(
                fn (string $position): string => self::key($modality, $position),
                [self::AUTO, ...$positions],
            ))
            ->all();
    }
}
