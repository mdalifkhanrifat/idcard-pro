<?php

namespace App\Services\Reports\Columns;

use App\Data\Reports\ReportColumn;
use App\Models\Cardholder;
use App\Models\CustomField;
use App\Repositories\Contracts\CustomFieldRepositoryInterface;
use App\Services\Cards\CardDataResolver;
use Closure;
use WeakMap;

/**
 * Report columns for a cardholder: every field a card can print (config card_template_elements.fields.cardholder,
 * master data included) and every active custom field of the organization, valued as the card prints them.
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

        $labels = (array) __('reports.fields');
        $columns = [];
        foreach ((array) config('card_template_elements.fields.cardholder') as $field) {
            $columns[$prefix.$field] = new ReportColumn($prefix.$field, (string) ($labels[$field] ?? $field), ReportColumn::TEXT, 'cardholder', $valueOf($field));
        }

        $local = $locale !== 'en';
        /** @var CustomField $custom */
        foreach ($this->customFields->listFor('cardholder', true) as $custom) {
            $key = "{$prefix}custom.{$custom->key}";
            $label = $local && filled($custom->label_local) ? (string) $custom->label_local : $custom->label;
            $columns[$key] = new ReportColumn($key, $label, ReportColumn::TEXT, 'custom', $valueOf("custom.{$custom->key}"));
        }

        return $columns;
    }
}
