<?php

namespace App\Support\Audit;

use Spatie\Activitylog\Models\Activity;

/**
 * A person's audit row made readable and safe before it is written. A record created or deleted keeps only the
 * fields that hold something (a new person's forty empty fields say nothing). A private field (config
 * audit.private: identifiers, contact details) says who changed it and when, its value never: null on both sides
 * of a change, and given or not on a record created or deleted.
 */
final class AuditValues
{
    public static function tidy(Activity $activity): void
    {
        $changes = $activity->attribute_changes?->toArray() ?? [];
        if ($changes === []) {
            return;
        }
        $new = (array) ($changes['attributes'] ?? []);
        $old = (array) ($changes['old'] ?? []);
        foreach (['attributes', 'old'] as $part) {
            foreach ((array) ($changes[$part] ?? []) as $key => $value) {
                $changed = array_key_exists($key, $new) && array_key_exists($key, $old);
                if (! $changed && blank($value)) {
                    unset($changes[$part][$key]);
                } elseif (self::isPrivate($activity->log_name, (string) $key)) {
                    $changes[$part][$key] = null;
                }
            }
        }
        $activity->attribute_changes = collect($changes);
    }

    public static function isPrivate(?string $logName, string $key): bool
    {
        return $logName !== null && in_array($key, (array) config("audit.private.{$logName}"), true);
    }
}
