<?php

namespace App\Data\Reports;

use Closure;
use DateTimeInterface;

/**
 * One column a report can show or export: its key, label in the reader's language, kind of value and how to
 * read the value from a row of the report.
 */
final readonly class ReportColumn
{
    /** Text (also numbers kept as text, e.g. phone numbers). */
    public const TEXT = 'text';

    public const NUMBER = 'number';

    /** A calendar day (DateTimeInterface, no time of day). */
    public const DATE = 'date';

    /** A moment, already in the organization's time zone. */
    public const DATETIME = 'datetime';

    /**
     * @param  string  $group  where the value comes from: the report's own data, the card, the cardholder, custom fields
     * @param  Closure  $value  (report row) => string|int|float|DateTimeInterface|null
     */
    public function __construct(
        public string $key,
        public string $label,
        public string $type,
        public string $group,
        public Closure $value,
    ) {}

    public function valueOf(object $row): string|int|float|DateTimeInterface|null
    {
        return ($this->value)($row);
    }

    /**
     * The value for JSON: a day as Y-m-d, a moment as ISO 8601 with the organization's offset.
     */
    public function jsonValueOf(object $row): string|int|float|null
    {
        $value = $this->valueOf($row);
        if ($value instanceof DateTimeInterface) {
            return $this->type === self::DATE ? $value->format('Y-m-d') : $value->format(DateTimeInterface::ATOM);
        }

        return $value;
    }

    /**
     * @return array{key: string, label: string, type: string, group: string}
     */
    public function toArray(): array
    {
        return ['key' => $this->key, 'label' => $this->label, 'type' => $this->type, 'group' => $this->group];
    }
}
