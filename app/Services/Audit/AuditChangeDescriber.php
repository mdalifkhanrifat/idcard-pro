<?php

namespace App\Services\Audit;

use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;

/**
 * What an audit row changed, for people: each field by its name in the reader's language ("Full name", "Card
 * ready — Send messages for this event") with its old and new value as text. The audit page, its report and the
 * API read it here, so a field is named once (config/audit.php).
 *
 * A field nobody named keeps its key; AuditFieldNamesTest fails then.
 */
final class AuditChangeDescriber
{
    /** @var array<string, array<string, mixed>> lang groups, by locale and group */
    private array $groups = [];

    /** @var array<string, list<string>> wildcard settings names, fewest wildcards first, by locale */
    private array $patterns = [];

    /**
     * The fields a record's change touched, in the order logged; null for an event that recorded other details
     * (a download's report, an override's reason...). `old` is null when there was no old value (created), `new`
     * when there is none (deleted).
     *
     * @return list<array{key: string, label: string, old: string|null, new: string|null}>|null
     */
    public function fields(Activity $activity): ?array
    {
        $changes = $activity->attribute_changes?->toArray() ?? [];
        $new = (array) ($changes['attributes'] ?? []);
        $old = (array) ($changes['old'] ?? []);
        if ($new === [] && $old === []) {
            return null;
        }

        return array_map(fn (string $key): array => [
            'key' => $key,
            'label' => $this->label((string) $activity->log_name, $key),
            'old' => array_key_exists($key, $old) ? $this->text($key, $old[$key]) : null,
            'new' => array_key_exists($key, $new) ? $this->text($key, $new[$key]) : null,
        ], array_map(strval(...), array_keys($new + $old)));
    }

    /**
     * A field of a record of the log ("cardholders", "rank_id" → "Rank / grade"), or an organization setting by its
     * dot path ("settings.notifications.enabled"); its key when nothing names it.
     */
    public function label(string $logName, string $key): string
    {
        $name = str_starts_with($key, 'settings.') ? $this->setting(substr($key, 9)) : $this->field($logName, $key);

        return $name ?? $key;
    }

    /**
     * A value as text: "—" for nothing, yes/no, a list joined, a hidden field never.
     */
    public function text(string $key, mixed $value): string
    {
        return match (true) {
            in_array(Str::afterLast($key, '.'), (array) config('audit.hidden'), true) => (string) __('audit.hidden'),
            $value === null, $value === '', $value === [] => '—',
            is_bool($value) => (string) __('reports.values.'.($value ? 'yes' : 'no')),
            is_array($value) && array_is_list($value) && array_filter($value, is_array(...)) === [] => Str::limit(implode(', ', array_map(fn (mixed $item): string => $this->text($key, $item), $value)), 200),
            is_array($value) => Str::limit((string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 200),
            default => Str::limit((string) $value, 200),
        };
    }

    /**
     * The audit's own name for this log's field, else the name reports, the cardholder lists or validation give it
     * (a reference "rank_id" also by "rank"); "*" names a field of any log.
     */
    private function field(string $logName, string $key): ?string
    {
        $base = str_ends_with($key, '_id') ? substr($key, 0, -3) : null;
        $sources = [
            [(array) ($this->group('audit.fields')[$logName] ?? []), [$key]],
            [(array) ($this->group('audit.fields')['*'] ?? []), [$key]],
            [$this->group('reports.fields'), [$key, $base]],
            [$this->group('cardholders.fields'), [$key]],
            [$this->group('validation.attributes'), [$key, $base]],
        ];
        foreach ($sources as [$names, $keys]) {
            foreach (array_filter($keys) as $candidate) {
                if (is_string($names[$candidate] ?? null)) {
                    return Str::ucfirst($names[$candidate]);
                }
            }
        }

        return null;
    }

    /**
     * A setting by the validation name of its path; each wildcard part is named by what it stands for and put
     * before it ("Card ready — Bangla — SMS text").
     */
    private function setting(string $path): ?string
    {
        $names = $this->group('validation.attributes');
        $pattern = is_string($names[$path] ?? null) ? $path : $this->pattern($path);
        if ($pattern === null) {
            return null;
        }

        $parts = explode('.', $path);
        $context = [];
        foreach (explode('.', $pattern) as $index => $part) {
            if ($part === '*') {
                $context[] = $this->segment(implode('.', array_slice(explode('.', $pattern), 0, $index + 1)), $parts[$index]);
            }
        }

        return implode(' — ', [...$context, Str::ucfirst((string) $names[$pattern])]);
    }

    /**
     * The most specific wildcard name that fits the path (fewest wildcards).
     */
    private function pattern(string $path): ?string
    {
        $locale = app()->getLocale();
        $this->patterns[$locale] ??= collect(array_keys($this->group('validation.attributes')))
            ->filter(fn (string $key): bool => str_contains($key, '*') && is_string($this->group('validation.attributes')[$key]))
            ->sortBy(fn (string $key): int => substr_count($key, '*'))
            ->values()->all();

        foreach ($this->patterns[$locale] as $pattern) {
            if (preg_match('/^'.str_replace('\*', '[^.]+', preg_quote($pattern, '/')).'$/', $path) === 1) {
                return $pattern;
            }
        }

        return null;
    }

    private function segment(string $prefix, string $part): string
    {
        // The registry's keys hold dots, so it is read whole (config() would read them as nesting)
        $key = ((array) config('audit.segments'))[$prefix] ?? null;
        if (! is_string($key)) {
            return $part;
        }
        $name = __(str_replace(':segment', $part, $key));

        return is_string($name) && $name !== str_replace(':segment', $part, $key) ? $name : $part;
    }

    /**
     * @return array<string, mixed>
     */
    private function group(string $key): array
    {
        $locale = app()->getLocale();

        return $this->groups[$locale][$key] ??= (array) __($key);
    }
}
