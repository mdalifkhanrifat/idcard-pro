<?php

namespace App\Services\Cardholders;

use App\Data\Cards\ImportCardData;
use App\Enums\CustomFieldType;
use App\Models\Cardholder;
use App\Models\CustomField;
use App\Models\MasterDataItem;
use App\Repositories\Contracts\MasterDataRepositoryInterface;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Turns spreadsheet rows into cardholder input: matches headers to fields,
 * normalizes values and resolves master data by name, local name or code.
 */
class CardholderImportMapper
{
    private const DATE_FIELDS = ['date_of_birth', 'joining_date', 'retirement_date'];

    private const ENUM_SYNONYMS = [
        'gender' => ['m' => 'male', 'f' => 'female'],
        'employment_type' => ['employee' => 'staff', 'permanent' => 'staff'],
    ];

    /**
     * Child master data column => parent column (children are looked up inside their parent).
     */
    private const PARENTS = ['unit_id' => 'department_id', 'sub_region_id' => 'region_id'];

    /**
     * @var array<string, array<string, MasterDataItem>> type => normalized key => item
     */
    private array $lookupCache = [];

    /**
     * @var Collection<string, CustomField>
     */
    private Collection $customFields;

    public function __construct(private MasterDataRepositoryInterface $masterData)
    {
        $this->customFields = collect();
    }

    /**
     * @param  Collection<int, CustomField>  $customFields  active custom fields
     */
    public function useCustomFields(Collection $customFields): self
    {
        $this->customFields = $customFields->keyBy('key');

        return $this;
    }

    /**
     * Map header cells to targets: a cardholder field, "custom:<key>" or "card:<field>" (the person's card).
     *
     * @param  array<int, string>  $headers
     * @return array{columns: array<int, string>, ignored: array<int, string>}
     */
    public function mapHeaders(array $headers): array
    {
        $aliases = [];
        foreach (config('cardholder_import.columns') as $field => $names) {
            foreach ([$field, ...$names] as $name) {
                $aliases[$this->normalize($name)] ??= $field;
            }
        }
        foreach (config('cardholder_import.card_columns') as $field => $names) {
            foreach ($names as $name) {
                $aliases[$this->normalize($name)] ??= "card:{$field}";
            }
        }
        foreach ($this->customFields as $field) {
            $aliases[$this->normalize($field->key)] ??= "custom:{$field->key}";
            $aliases[$this->normalize($field->label)] ??= "custom:{$field->key}";
            if ($field->label_local) {
                $aliases[$this->normalize($field->label_local)] ??= "custom:{$field->key}";
            }
        }

        $columns = [];
        $ignored = [];
        foreach ($headers as $index => $header) {
            $target = $aliases[$this->normalize($header)] ?? null;
            if ($target !== null && ! in_array($target, $columns, true)) {
                $columns[$index] = $target;
            } elseif (trim($header) !== '') {
                $ignored[] = $header;
            }
        }

        return ['columns' => $columns, 'ignored' => $ignored];
    }

    /**
     * Build validated-request-shaped input from one row.
     *
     * @param  array<int, string>  $cells
     * @param  array<int, string>  $columns
     * @param  bool  $createMissing  create unknown master data items (dry runs only report them)
     * @return array{input: array<string, mixed>, errors: array<string, string>, created: array<int, string>}
     */
    public function toInput(array $cells, array $columns, bool $createMissing, bool $dryRun): array
    {
        $input = [];
        $custom = [];
        $raw = [];
        foreach ($columns as $index => $target) {
            $raw[$target] = trim((string) ($cells[$index] ?? ''));
        }

        foreach ($raw as $target => $value) {
            if (str_starts_with($target, 'card:')) {
                continue;
            }
            if (str_starts_with($target, 'custom:')) {
                $field = $this->customFields[substr($target, 7)] ?? null;
                if ($field && $value !== '') {
                    $custom[$field->key] = $this->customValue($field, $value);
                }
            } elseif (! array_key_exists($target, Cardholder::LOOKUPS)) {
                $input[$target] = $value === '' ? null : $this->fieldValue($target, $value);
            }
        }
        if ($custom !== []) {
            $input['custom_fields'] = $custom;
        }

        $errors = [];
        $created = [];
        // Parents first so children can be looked up inside them.
        $lookupColumns = array_keys(array_intersect_key(Cardholder::LOOKUPS, $raw));
        usort($lookupColumns, fn (string $a, string $b) => isset(self::PARENTS[$a]) <=> isset(self::PARENTS[$b]));

        foreach ($lookupColumns as $column) {
            $value = $raw[$column];
            if ($value === '') {
                $input[$column] = null;

                continue;
            }

            $type = Cardholder::LOOKUPS[$column];
            $parentColumn = self::PARENTS[$column] ?? null;
            $parent = null;
            if ($parentColumn !== null) {
                $parent = isset($input[$parentColumn]) ? $this->findByPublicId(Cardholder::LOOKUPS[$parentColumn], $input[$parentColumn]) : null;
                if ($parent === null) {
                    // In a dry run the parent may only be scheduled for creation; then so is the child.
                    if (in_array(Cardholder::LOOKUPS[$parentColumn].': '.($raw[$parentColumn] ?? ''), $created, true)) {
                        $created[] = "{$type}: {$value}";
                    } else {
                        $errors[$column] = __('cardholders.import.parent_required', ['parent' => __("cardholders.fields.{$parentColumn}")]);
                    }

                    continue;
                }
            }

            $item = $this->find($type, $value, $parent);
            if ($item === null && $createMissing) {
                $created[] = "{$type}: {$value}";
                if (! $dryRun) {
                    $item = $this->create($type, $value, $parent);
                }
            }

            if ($item !== null) {
                $input[$column] = $item->public_id;
            } elseif (! $createMissing) {
                $errors[$column] = __('cardholders.import.unknown_lookup', ['value' => $value, 'list' => __("cardholders.fields.{$column}")]);
            }
        }

        return ['input' => $input, 'errors' => $errors, 'created' => $created];
    }

    /**
     * The row's card ("card:" columns). Dates and times are in the organization's time zone; the last
     * printing is returned in UTC.
     *
     * @param  array<int, string>  $cells
     * @param  array<int, string>  $columns
     * @return array{data: ImportCardData, errors: array<string, string>}
     */
    public function toCard(array $cells, array $columns, string $timezone): array
    {
        $raw = [];
        foreach ($columns as $index => $target) {
            if (str_starts_with($target, 'card:')) {
                $raw[substr($target, 5)] = trim((string) ($cells[$index] ?? ''));
            }
        }
        $value = fn (string $field): ?string => ($raw[$field] ?? '') === '' ? null : $raw[$field];
        $errors = [];

        foreach (['number' => 60, 'rf_card_no' => 60, 'notes' => 1000] as $field => $max) {
            if (mb_strlen((string) $value($field)) > $max) {
                $errors[$field] = __('validation.max.string', ['attribute' => __("cardholders.import.card_fields.{$field}"), 'max' => $max]);
            }
        }

        $dates = [];
        $printTime = null;
        foreach (['issue_date', 'expiry_date', 'last_print_date'] as $field) {
            if ($value($field) === null) {
                continue;
            }
            [$date, $time] = $this->dateTime($value($field));
            if ($date === null) {
                $errors[$field] = __('cardholders.import.card_invalid_date', ['field' => __("cardholders.import.card_fields.{$field}"), 'value' => $value($field)]);
            } else {
                $dates[$field] = $date;
                if ($field === 'last_print_date') {
                    // A print date may carry its time (e.g. "2024-03-02 14:30" or an Excel date-time)
                    $printTime = $time;
                }
            }
        }

        if ($value('last_print_time') !== null) {
            $printTime = $this->time($value('last_print_time'));
            if ($printTime === null) {
                $errors['last_print_time'] = __('cardholders.import.card_invalid_time', ['value' => $value('last_print_time')]);
            } elseif ($value('last_print_date') === null) {
                $errors['last_print_time'] = __('cardholders.import.card_time_without_date');
            }
        }

        $day = fn (string $field): ?CarbonImmutable => isset($dates[$field]) ? CarbonImmutable::parse($dates[$field])->startOfDay() : null;
        $printedAt = isset($dates['last_print_date'])
            ? CarbonImmutable::parse("{$dates['last_print_date']} ".($printTime ?? '00:00:00'), $timezone)->utc()
            : null;

        return [
            'data' => new ImportCardData(
                number: $value('number'),
                issueDate: $day('issue_date'),
                expiryDate: $day('expiry_date'),
                rfCardNo: $value('rf_card_no'),
                notes: $value('notes'),
                lastPrintedAt: $printedAt,
            ),
            'errors' => $errors,
        ];
    }

    private function fieldValue(string $field, string $value): string
    {
        if (in_array($field, self::DATE_FIELDS, true)) {
            return $this->date($value);
        }
        if (in_array($field, ['gender', 'marital_status', 'employment_type', 'status'], true)) {
            $key = Str::snake(strtolower($value));

            return self::ENUM_SYNONYMS[$field][$key] ?? $key;
        }

        return $value;
    }

    private function customValue(CustomField $field, string $value): string|bool
    {
        return match ($field->type) {
            CustomFieldType::Date => $this->date($value),
            CustomFieldType::Checkbox => in_array(mb_strtolower($value), ['1', 'yes', 'y', 'true', 'হ্যাঁ'], true),
            default => $value,
        };
    }

    /**
     * Accepts Y-m-d, d/m/Y, d-m-Y, d.m.Y and Excel serial numbers; anything else is
     * returned unchanged so validation reports it.
     */
    private function date(string $value): string
    {
        if (preg_match('/^\d{4,5}(\.\d+)?$/', $value) && (float) $value > 59 && (float) $value < 80000) {
            return CarbonImmutable::create(1899, 12, 30)->addDays((int) $value)->toDateString();
        }
        if (preg_match('/^(\d{4})[-\/.](\d{1,2})[-\/.](\d{1,2})$/', $value, $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]);
        }
        if (preg_match('/^(\d{1,2})[-\/.](\d{1,2})[-\/.](\d{4})$/', $value, $m) && checkdate((int) $m[2], (int) $m[1], (int) $m[3])) {
            return sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
        }

        return $value;
    }

    /**
     * A date, optionally with a time ("2024-03-02 14:30:00", "02/03/2024 2:30 PM", Excel date-time serials).
     *
     * @return array{0: string|null, 1: string|null} Y-m-d (null when unreadable) and H:i:s (null when none)
     */
    private function dateTime(string $value): array
    {
        if (preg_match('/^\d{4,5}\.\d+$/', $value)) {
            [$days, $fraction] = explode('.', $value);

            return [$this->validDate($this->date($days)), $this->time("0.{$fraction}")];
        }

        $parts = preg_split('/[\sT]+/', $value, 2) ?: [$value];
        $time = isset($parts[1]) ? $this->time($parts[1]) : null;
        if (isset($parts[1]) && $time === null) {
            return [null, null];
        }

        return [$this->validDate($this->date($parts[0])), $time];
    }

    private function validDate(string $value): ?string
    {
        return preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? $value : null;
    }

    /**
     * H:i:s from "14:30", "14:30:05", "2:30 PM", "2:30:05 pm" or an Excel day fraction ("0.6045").
     */
    private function time(string $value): ?string
    {
        $value = trim($value);
        if (preg_match('/^0?\.\d+$/', $value)) {
            $seconds = (int) round((float) $value * 86400);

            return $seconds < 86400 ? sprintf('%02d:%02d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60) : null;
        }
        if (! preg_match('/^(\d{1,2}):(\d{2})(?::(\d{2}))?\s*([AaPp][Mm])?$/', $value, $m)) {
            return null;
        }
        [$hour, $minute, $second] = [(int) $m[1], (int) $m[2], (int) ($m[3] ?? 0)];
        if (isset($m[4])) {
            if ($hour < 1 || $hour > 12) {
                return null;
            }
            $hour = $hour % 12 + (strtolower($m[4]) === 'pm' ? 12 : 0);
        }

        return $hour < 24 && $minute < 60 && $second < 60 ? sprintf('%02d:%02d:%02d', $hour, $minute, $second) : null;
    }

    private function find(string $type, string $value, ?MasterDataItem $parent): ?MasterDataItem
    {
        return $this->items($type)[$this->lookupKey($value, $parent)] ?? null;
    }

    private function findByPublicId(string $type, string $publicId): ?MasterDataItem
    {
        foreach ($this->items($type) as $item) {
            if ($item->public_id === $publicId) {
                return $item;
            }
        }

        return null;
    }

    private function create(string $type, string $name, ?MasterDataItem $parent): MasterDataItem
    {
        $item = $this->masterData->create(['type' => $type, 'name' => Str::limit($name, 255, ''), 'parent_id' => $parent?->id]);
        $this->lookupCache[$type][$this->lookupKey($name, $parent)] = $item;

        return $item;
    }

    /**
     * @return array<string, MasterDataItem>
     */
    private function items(string $type): array
    {
        if (! isset($this->lookupCache[$type])) {
            $this->lookupCache[$type] = [];
            foreach ($this->masterData->allOfType($type) as $item) {
                $parent = $item->parent_id ? (object) ['id' => $item->parent_id] : null;
                foreach (array_filter([$item->name, $item->name_local, $item->code]) as $label) {
                    $this->lookupCache[$type][$this->lookupKey($label, $parent)] ??= $item;
                }
            }
        }

        return $this->lookupCache[$type];
    }

    private function lookupKey(string $value, MasterDataItem|\stdClass|null $parent): string
    {
        return ($parent->id ?? 0).'|'.mb_strtolower(trim($value));
    }

    private function normalize(string $header): string
    {
        $header = preg_replace('/^\xEF\xBB\xBF/', '', $header);

        return trim((string) preg_replace('/[\s_\-.]+/u', ' ', mb_strtolower((string) $header)));
    }
}
