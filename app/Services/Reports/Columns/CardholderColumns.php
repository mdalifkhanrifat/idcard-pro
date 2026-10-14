<?php

namespace App\Services\Reports\Columns;

use App\Data\Reports\ReportColumn;
use App\Enums\CustomFieldType;
use App\Models\Cardholder;
use App\Models\CustomField;
use App\Repositories\Contracts\CustomFieldRepositoryInterface;
use App\Services\Cards\CardDataResolver;
use Carbon\CarbonImmutable;
use Closure;
use DateTimeInterface;
use WeakMap;

/**
 * Report columns for a cardholder: every field a card can print (config card_template_elements.fields.cardholder,
 * master data included) and every active custom field of the organization, valued as the card prints them;
 * days (date of birth, joining date... and date custom fields) as days and number custom fields as numbers, so
 * they read in the reader's digits, sort and land in spreadsheets as dates and numbers.
 * A new cardholder field or custom field becomes a report column without changing any report.
 */
class CardholderColumns
{
    public function __construct(
        private CardDataResolver $resolver,
        private CustomFieldRepositoryInterface $customFields,
    ) {}

    /**
     * @param  Closure  $holderOf  (report row) => ?Cardholder
     * @param  string  $prefix  column key prefix, e.g. "cardholder."
     * @return array<string, ReportColumn>
     */
    public function for(Closure $holderOf, string $prefix = 'cardholder.'): array
    {
        $locale = app()->getLocale();
        // One lookup of a cardholder's values per row, however many of its columns are shown
        $cache = new WeakMap;
        $valueOf = function (string $field) use ($holderOf, $locale, &$cache): Closure {
            return function (object $row) use ($field, $holderOf, $locale, &$cache): ?string {
                $holder = $holderOf($row);
                if ($holder === null) {
                    return null;
                }
                $cache[$holder] ??= $this->resolver->holderFields($holder, $locale);

                return ($cache[$holder][$field] ?? '') === '' ? null : $cache[$holder][$field];
            };
        };

        $dayOf = fn (string $field): Closure => fn (object $row): ?DateTimeInterface => $holderOf($row)?->{$field};
        $customOf = fn (string $key): Closure => fn (object $row): mixed => $holderOf($row)?->custom_fields[$key] ?? null;

        $labels = (array) __('reports.fields');
        $columns = [];
        foreach ((array) config('card_template_elements.fields.cardholder') as $field) {
            $label = (string) ($labels[$field] ?? $field);
            $columns[$prefix.$field] = in_array($field, CardDataResolver::DATE_COLUMNS, true)
                ? new ReportColumn($prefix.$field, $label, ReportColumn::DATE, 'cardholder', $dayOf($field))
                : new ReportColumn($prefix.$field, $label, ReportColumn::TEXT, 'cardholder', $valueOf($field));
        }

        $local = $locale !== 'en';
        /** @var CustomField $custom */
        foreach ($this->customFields->listFor('cardholder', true) as $custom) {
            $key = "{$prefix}custom.{$custom->key}";
            $label = $local && filled($custom->label_local) ? (string) $custom->label_local : $custom->label;
            $raw = $customOf($custom->key);
            $columns[$key] = match ($custom->type) {
                CustomFieldType::Date => new ReportColumn($key, $label, ReportColumn::DATE, 'custom', fn (object $row) => self::day($raw($row))),
                CustomFieldType::Number => new ReportColumn($key, $label, ReportColumn::NUMBER, 'custom', fn (object $row) => self::number($raw($row))),
                default => new ReportColumn($key, $label, ReportColumn::TEXT, 'custom', $valueOf("custom.{$custom->key}")),
            };
        }

        return $columns;
    }

    /**
     * A stored day (Y-m-d, as custom fields keep it); null when empty or not a day.
     */
    private static function day(mixed $value): ?DateTimeInterface
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }
        $day = CarbonImmutable::createFromFormat('!Y-m-d', $value);

        return $day !== null && $day->format('Y-m-d') === $value ? $day : null;
    }

    /**
     * A stored number (as given, or as numeric text); null when empty or not a number.
     */
    private static function number(mixed $value): int|float|null
    {
        if (is_int($value) || is_float($value)) {
            return $value;
        }

        return is_string($value) && is_numeric(trim($value)) ? trim($value) + 0 : null;
    }
}
