<?php

namespace App\Services\Audit;

use App\Services\Reports\ReportValueText;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;
use Throwable;

/**
 * What an audit row changed, for people: each field by its name in the reader's language ("Full name", "Card
 * ready — Send messages for this event") with its old and new value as people read it ("Replaced", "Sergeant",
 * "24/10/2026 18:30", Bangla digits for Bangla readers). The audit page, its report and the API read it here, so a
 * field and a value are named once (config/audit.php).
 *
 * A field nobody named keeps its key, a value nobody named stays as stored; AuditFieldNamesTest fails then.
 */
final class AuditChangeDescriber
{
    /** @var array<string, array{0: string, 1: mixed}|null> how a field's values read, by subject type, log and field */
    private array $kinds = [];

    /** @var array<class-string<Model>, array<int, string|null>> names of referenced records (null: no longer there) */
    private array $references = [];

    public function __construct(private ReportValueText $values, private TenantContext $tenant) {}

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
        [$new, $old] = $this->recorded($activity);
        if ($new === [] && $old === []) {
            return null;
        }

        return array_map(fn (string $key): array => [
            'key' => $key,
            'label' => $this->label((string) $activity->log_name, $key),
            'old' => array_key_exists($key, $old) ? $this->value($activity, $key, $old[$key]) : null,
            'new' => array_key_exists($key, $new) ? $this->value($activity, $key, $new[$key]) : null,
        ], array_map(strval(...), array_keys($new + $old)));
    }

    /**
     * New and old values: from the record's own logging, or from a change noted by hand (a role's permissions, a
     * user's roles — RecordedChanges keeps the same two parts). An event's own facts are not field changes.
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function recorded(Activity $activity): array
    {
        $changes = $activity->attribute_changes?->toArray() ?? [];
        if ($changes === []) {
            $properties = $activity->properties?->toArray() ?? [];
            $changes = array_diff(array_keys($properties), ['attributes', 'old']) === [] ? $properties : [];
        }

        return [(array) ($changes['attributes'] ?? []), (array) ($changes['old'] ?? [])];
    }

    /**
     * Look up the records a page of rows refers to ("rank_id": 54) at once: one query per kind of record, not one
     * per row. Rows described without it still read right, a query at a time.
     *
     * @param  iterable<Activity>  $activities
     */
    public function prime(iterable $activities): void
    {
        $wanted = [];
        foreach ($activities as $activity) {
            foreach ($this->recorded($activity) as $values) {
                foreach ($values as $key => $value) {
                    $kind = $this->kind($activity, (string) $key);
                    if ($kind !== null && $kind[0] === 'reference' && is_numeric($value)) {
                        $wanted[$kind[1]][] = (int) $value;
                    }
                }
            }
        }
        foreach ($wanted as $class => $ids) {
            $this->load($class, $ids);
        }
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
     * A value as text, whatever it belongs to: "—" for nothing, yes/no, a list joined, a number in the reader's
     * digits, a hidden field never.
     */
    public function text(string $key, mixed $value): string
    {
        return match (true) {
            $this->hidden($key) => (string) __('audit.hidden'),
            $value === null, $value === '', $value === [] => '—',
            is_bool($value) => (string) __('reports.values.'.($value ? 'yes' : 'no')),
            is_array($value) && array_is_list($value) && array_filter($value, is_array(...)) === [] => Str::limit(implode(', ', array_map(fn (mixed $item): string => $this->text($key, $item), $value)), 200),
            is_array($value) => Str::limit((string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 200),
            is_int($value), is_float($value) => $this->values->number($value),
            default => Str::limit((string) $value, 200),
        };
    }

    /**
     * A value of a record's field or a setting as people read it: an enum's or a choice's name, the name of the
     * record a reference points to, a day or a moment in the organization's time, a number in the reader's digits.
     */
    private function value(Activity $activity, string $key, mixed $value): string
    {
        $kind = $this->kind($activity, $key);
        if ($kind === null || $value === null || $value === '' || is_bool($value) || $this->hidden($key)) {
            return $this->text($key, $value);
        }
        if (is_array($value)) {
            return array_is_list($value) && array_filter($value, is_array(...)) === []
                ? Str::limit(implode(', ', array_map(fn (mixed $item): string => $this->scalar($kind, $item), $value)), 2000)
                : $this->text($key, $value);
        }

        return Str::limit($this->scalar($kind, $value), 200);
    }

    /**
     * @param  array{0: string, 1: mixed}  $kind
     */
    private function scalar(array $kind, mixed $value): string
    {
        if (! is_scalar($value)) {
            return $this->text('', $value);
        }

        return match ($kind[0]) {
            'names' => $this->named((array) $kind[1], (string) $value),
            'joined' => $this->joined((array) $kind[1], (string) $value),
            'reference' => $this->reference($kind[1], $value),
            'date' => $this->values->date(substr((string) $value, 0, 10)),
            'datetime' => $this->moment((string) $value),
            default => (string) $value,
        };
    }

    /**
     * A value of two parts, each named by its own lang array: "Issued cards: View"; as stored unless both are named.
     *
     * @param  array<int, string>  $groups
     */
    private function joined(array $groups, string $value): string
    {
        $parts = array_map(
            fn (string $part, string $group): ?string => $this->group($group)[$part] ?? null,
            explode('.', $value, 2),
            $groups,
        );

        return in_array(null, $parts, true) ? $value : implode(': ', $parts);
    }

    /**
     * The first name the lang arrays give the value, else the value.
     *
     * @param  array<int, string>  $groups
     */
    private function named(array $groups, string $value): string
    {
        foreach ($groups as $group) {
            $name = $this->group($group)[$value] ?? null;
            if (is_string($name)) {
                return $name;
            }
        }

        return $value;
    }

    private function reference(string $class, mixed $id): string
    {
        if (! is_numeric($id)) {
            return (string) $id;
        }
        $this->load($class, [(int) $id]);

        return $this->references[$class][(int) $id] ?? (string) __('audit.deleted_record', ['id' => $this->values->digits((string) $id)]);
    }

    /**
     * A moment stored in UTC, in the organization's time.
     */
    private function moment(string $value): string
    {
        try {
            $moment = CarbonImmutable::parse($value)->setTimezone($this->tenant->get()->timezone ?? config('app.timezone'));
        } catch (Throwable) {
            return $value;
        }

        return $this->values->digits($moment->format('d/m/Y H:i'));
    }

    /**
     * @param  class-string<Model>  $class
     * @param  array<int, int>  $ids
     */
    private function load(string $class, array $ids): void
    {
        $missing = array_values(array_diff(array_unique($ids), array_keys($this->references[$class] ?? [])));
        if ($missing === []) {
            return;
        }
        $query = $class::query();
        if (in_array(SoftDeletes::class, class_uses_recursive($class), true)) {
            // A record deleted since still has its name; the organization's own scope stays
            $query->withoutGlobalScope(SoftDeletingScope::class);
        }
        $column = (string) ((array) config('audit.references'))[$class];
        $found = $query->whereKey($missing)->pluck($column, (new $class)->getKeyName())->all();
        foreach ($missing as $id) {
            $this->references[$class][$id] = isset($found[$id]) ? (string) $found[$id] : null;
        }
    }

    /**
     * How a field's values read: named by lang arrays (an enum's cast, or config audit.values), a reference to a
     * record (config audit.references), a day or a moment (the model's date casts); null for as stored.
     *
     * @return array{0: string, 1: mixed}|null
     */
    private function kind(Activity $activity, string $key): ?array
    {
        $log = (string) $activity->log_name;
        $type = (string) $activity->subject_type;

        $cacheKey = "{$type}|{$log}|{$key}";
        if (! array_key_exists($cacheKey, $this->kinds)) {
            $this->kinds[$cacheKey] = $this->findKind($type, $log, $key);
        }

        return $this->kinds[$cacheKey];
    }

    /**
     * @return array{0: string, 1: mixed}|null
     */
    private function findKind(string $type, string $log, string $key): ?array
    {
        $groups = $this->valueGroups($log, $key);
        if ($groups !== null) {
            return ['names', $groups];
        }
        $joined = ((array) config('audit.joined'))["{$log}.{$key}"] ?? null;
        if ($joined !== null) {
            return ['joined', $joined];
        }
        $class = $type === '' ? null : (Relation::getMorphedModel($type) ?? $type);
        if (str_starts_with($key, 'settings.') || $class === null || ! is_subclass_of($class, Model::class)) {
            return null;
        }
        $model = new $class;

        $cast = $model->getCasts()[$key] ?? null;
        if (is_string($cast)) {
            $enum = Str::before($cast, ':');
            $enumNames = ((array) config('audit.enums'))[$enum] ?? null;
            if (is_string($enumNames)) {
                return ['names', [$enumNames]];
            }
            if (in_array($enum, ['date', 'immutable_date'], true)) {
                return ['date', null];
            }
            if (in_array($enum, ['datetime', 'immutable_datetime', 'timestamp'], true)) {
                return ['datetime', null];
            }
        }

        if (str_ends_with($key, '_id')) {
            $relation = ((array) config('audit.relations'))["{$log}.{$key}"] ?? Str::camel(substr($key, 0, -3));
            if (method_exists($model, $relation) && ($belongs = $model->{$relation}()) instanceof BelongsTo) {
                $related = $belongs->getRelated()::class;
                if (array_key_exists($related, (array) config('audit.references'))) {
                    return ['reference', $related];
                }
            }
        }

        return null;
    }

    /**
     * The lang arrays that name a field's values (config audit.values): "<log>.<field>", "*.<field>" or a settings
     * path, wildcards included.
     *
     * @return list<string>|null
     */
    private function valueGroups(string $log, string $key): ?array
    {
        $registry = (array) config('audit.values');
        $groups = str_starts_with($key, 'settings.')
            ? ($registry[$key] ?? $this->matching($registry, $key))
            : ($registry["{$log}.{$key}"] ?? $registry["*.{$key}"] ?? null);

        return $groups === null ? null : array_values((array) $groups);
    }

    /**
     * @param  array<string, mixed>  $registry
     */
    private function matching(array $registry, string $key): mixed
    {
        foreach ($registry as $pattern => $groups) {
            if (str_contains($pattern, '*') && preg_match('/^'.str_replace('\*', '[^.]+', preg_quote($pattern, '/')).'$/', $key) === 1) {
                return $groups;
            }
        }

        return null;
    }

    private function hidden(string $key): bool
    {
        return in_array(Str::afterLast($key, '.'), (array) config('audit.hidden'), true);
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
