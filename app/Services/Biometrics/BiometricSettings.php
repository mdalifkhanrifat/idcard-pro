<?php

namespace App\Services\Biometrics;

/**
 * An organization's biometric settings (tenants.settings.biometrics), always complete: anything not
 * saved takes the configured default, and values the registry no longer offers (a removed position,
 * a driver turned off such as the simulator in production) are dropped.
 */
class BiometricSettings
{
    /**
     * Drivers this installation offers, with what each can capture and whether it only makes up data.
     *
     * @return array<string, array{modalities: array<int, string>, local_url: string|null, testing: bool, client: array<string, mixed>}>
     */
    public static function drivers(): array
    {
        $drivers = [];
        foreach ((array) config('biometrics.drivers') as $name => $driver) {
            if (($driver['testing'] ?? false) && ! config('biometrics.simulator')) {
                continue;
            }
            $drivers[$name] = ['modalities' => array_values((array) $driver['modalities']), 'local_url' => $driver['local_url'] ?? null, 'testing' => (bool) ($driver['testing'] ?? false), 'client' => (array) ($driver['client'] ?? [])];
        }

        return $drivers;
    }

    /**
     * Drivers that can capture a modality.
     *
     * @return array<int, string>
     */
    public static function driversFor(string $modality): array
    {
        return array_keys(array_filter(self::drivers(), fn (array $driver): bool => in_array($modality, $driver['modalities'], true)));
    }

    /**
     * Modalities whose image the organization prints on cards, each with the positions a card designer
     * element may name: any position of the registry (a stand-in may be the one captured). Empty while
     * biometrics or printing on cards are off, or for a modality not in use or not printable.
     *
     * @param  array{enabled: bool, modalities: array<string, array{mode: string}>, print_on_card: bool}  $settings  normalized
     * @return array<string, array<int, string>>
     */
    public static function printable(array $settings): array
    {
        if (! $settings['enabled'] || ! $settings['print_on_card']) {
            return [];
        }

        $printable = [];
        foreach ((array) config('biometrics.modalities') as $name => $modality) {
            if (($modality['printable'] ?? false) && ($settings['modalities'][$name]['mode'] ?? 'off') !== 'off') {
                $printable[$name] = array_keys((array) $modality['positions']);
            }
        }

        return $printable;
    }

    /**
     * Apply a change to the current settings. Values not sent stay as they are and a modality not sent
     * is kept whole; lists (positions, drivers, required_before) that are missing mean none, because a
     * form cannot send an empty list.
     *
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>  $change
     * @return array<string, mixed>
     */
    public static function merge(array $current, array $change): array
    {
        $merged = [...$current, ...$change, 'required_before' => (array) ($change['required_before'] ?? [])];
        $merged['modalities'] = (array) ($current['modalities'] ?? []);
        foreach ((array) ($change['modalities'] ?? []) as $name => $modality) {
            $merged['modalities'][$name] = [
                ...(array) ($current['modalities'][$name] ?? []),
                ...(array) $modality,
                'positions' => (array) ($modality['positions'] ?? []),
                'drivers' => (array) ($modality['drivers'] ?? []),
            ];
        }

        return $merged;
    }

    /**
     * @param  array<string, mixed>  $saved
     * @return array{enabled: bool, modalities: array<string, array{mode: string, count: int, positions: array<int, string>, substitutes: bool, min_quality: int, max_attempts: int, drivers: array<int, string>}>, family_members: bool, required_before: array<int, string>, print_on_card: bool, print_source: string}
     */
    public static function normalize(array $saved): array
    {
        $defaults = (array) config('biometrics.defaults');
        $modalities = [];
        foreach ((array) config('biometrics.modalities') as $name => $modality) {
            $modalities[$name] = self::modality($name, array_keys((array) $modality['positions']), (array) ($saved['modalities'][$name] ?? []), (array) ($defaults['modalities'][$name] ?? []));
        }

        return [
            'enabled' => (bool) ($saved['enabled'] ?? $defaults['enabled']),
            'modalities' => $modalities,
            'family_members' => (bool) ($saved['family_members'] ?? $defaults['family_members']),
            'required_before' => array_values(array_intersect((array) ($saved['required_before'] ?? $defaults['required_before']), (array) config('biometrics.required_before'))),
            'print_on_card' => (bool) ($saved['print_on_card'] ?? $defaults['print_on_card']),
            'print_source' => in_array($saved['print_source'] ?? null, (array) config('biometrics.print_sources'), true) ? $saved['print_source'] : $defaults['print_source'],
        ];
    }

    /**
     * @param  array<int, string>  $offered
     * @param  array<string, mixed>  $saved
     * @param  array<string, mixed>  $default
     * @return array{mode: string, count: int, positions: array<int, string>, substitutes: bool, min_quality: int, max_attempts: int, drivers: array<int, string>}
     */
    private static function modality(string $name, array $offered, array $saved, array $default): array
    {
        $value = fn (string $key, mixed $fallback): mixed => $saved[$key] ?? $default[$key] ?? $fallback;
        $mode = in_array($value('mode', 'off'), (array) config('biometrics.modes'), true) ? $value('mode', 'off') : 'off';
        // In the organization's order of preference; positions no longer offered are dropped
        $positions = array_values(array_unique(array_intersect((array) $value('positions', []), $offered)));
        $substitutes = (bool) $value('substitutes', false);
        // Without stand-ins only the chosen positions count, so no more than those can be asked for
        $count = max(1, min((int) $value('count', 1), $substitutes ? count($offered) : max(1, count($positions))));

        return [
            'mode' => $mode,
            'count' => $count,
            'positions' => $positions,
            'substitutes' => $substitutes,
            'min_quality' => max(0, min(100, (int) $value('min_quality', 0))),
            'max_attempts' => max(1, min(10, (int) $value('max_attempts', 3))),
            'drivers' => array_values(array_intersect((array) $value('drivers', []), self::driversFor($name))),
        ];
    }
}
