<?php

namespace App\Support\Audit;

use Illuminate\Database\Eloquent\Model;

/**
 * Notes in the audit log a change the model's own logging does not see (e.g. a role's permissions, a user's
 * roles — kept in pivot tables), in the shape the model's own entries have: `attributes` (after) and `old`
 * (before), only what changed; lists compared as sets, sorted. Nothing is noted when nothing changed.
 */
final class RecordedChanges
{
    /**
     * @param  'created'|'updated'|'deleted'  $event
     * @param  array<string, mixed>  $old  before (empty when created)
     * @param  array<string, mixed>  $new  after (empty when deleted)
     */
    public static function log(string $logName, Model $subject, string $event, array $old, array $new): void
    {
        $old = array_map(self::normal(...), $old);
        $new = array_map(self::normal(...), $new);
        $changed = array_values(array_filter(array_unique([...array_keys($old), ...array_keys($new)]), fn (string $key): bool => ($old[$key] ?? null) !== ($new[$key] ?? null)));
        if ($changed === []) {
            return;
        }
        $properties = array_filter([
            'attributes' => $event === 'deleted' ? null : array_intersect_key($new, array_flip($changed)),
            'old' => $event === 'created' ? null : array_intersect_key($old, array_flip($changed)),
        ], fn (?array $part): bool => $part !== null);

        activity($logName)->performedOn($subject)->event($event)->withProperties($properties)->log($event);
    }

    private static function normal(mixed $value): mixed
    {
        if (is_array($value) && array_is_list($value)) {
            sort($value);
        }

        return $value;
    }
}
