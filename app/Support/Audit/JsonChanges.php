<?php

namespace App\Support\Audit;

/**
 * What changed inside a JSON column (e.g. an organization's settings), for the audit log: only the values that
 * changed, keyed by dot path under the column ("settings.biometrics.print_on_card"), old and new. Lists (e.g. the
 * preferred fingers in order) are one value, so a reordered list reads as one change.
 */
final class JsonChanges
{
    /**
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     * @return array{old: array<string, mixed>, new: array<string, mixed>}
     */
    public static function between(array $old, array $new, string $prefix): array
    {
        $before = self::flatten($old, $prefix);
        $after = self::flatten($new, $prefix);
        $changes = ['old' => [], 'new' => []];
        foreach (array_unique([...array_keys($before), ...array_keys($after)]) as $key) {
            $was = $before[$key] ?? null;
            $is = $after[$key] ?? null;
            if ($was !== $is) {
                $changes['old'][$key] = $was;
                $changes['new'][$key] = $is;
            }
        }

        return $changes;
    }

    /**
     * Decode a JSON column value as the activity log may hold it (decoded already, or as text).
     *
     * @return array<string, mixed>
     */
    public static function decode(mixed $value): array
    {
        return is_string($value) ? (array) json_decode($value, true) : (array) $value;
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private static function flatten(array $values, string $prefix): array
    {
        $flat = [];
        foreach ($values as $key => $value) {
            $path = "{$prefix}.{$key}";
            if (is_array($value) && $value !== [] && ! array_is_list($value)) {
                $flat = [...$flat, ...self::flatten($value, $path)];
            } else {
                $flat[$path] = $value;
            }
        }

        return $flat;
    }
}
