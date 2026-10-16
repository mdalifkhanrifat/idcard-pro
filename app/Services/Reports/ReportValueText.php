<?php

namespace App\Services\Reports;

use App\Data\Reports\ReportColumn;
use App\Support\Localization\LocalDigits;
use DateTimeInterface;

/**
 * Values as people read them in report documents: days as dd/mm/yyyy (as cards print them), moments with the
 * time, numbers grouped, and Bangla digits for Bangla readers.
 */
class ReportValueText
{
    public function of(ReportColumn $column, string|int|float|DateTimeInterface|null $value): string
    {
        return match (true) {
            $value === null || $value === '' => '',
            $value instanceof DateTimeInterface => $this->digits($value->format($column->type === ReportColumn::DATE ? 'd/m/Y' : 'd/m/Y H:i')),
            is_int($value) || is_float($value) => $this->number($value),
            default => (string) $value,
        };
    }

    /**
     * A Y-m-d day as dd/mm/yyyy.
     */
    public function date(string $day): string
    {
        $parts = explode('-', $day);

        return $this->digits(count($parts) === 3 ? "{$parts[2]}/{$parts[1]}/{$parts[0]}" : $day);
    }

    public function number(int|float $value): string
    {
        return $this->digits(number_format($value, is_float($value) && floor($value) !== $value ? 2 : 0));
    }

    /**
     * Digits in the reader's script (Bangla digits when the language is Bangla).
     */
    public function digits(string $text): string
    {
        return app(LocalDigits::class)->convert($text);
    }
}
