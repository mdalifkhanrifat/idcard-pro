<?php

namespace App\Data\Reports;

use Illuminate\Validation\Rule;

/**
 * One way a report can be filtered: what the request accepts (`filter[key]`) and what a client needs to draw it,
 * so a new report's filters need no new page.
 */
final readonly class ReportFilterField
{
    /** Free text (card number, name...). */
    public const SEARCH = 'search';

    /** A day, Y-m-d; `after` names the field it may not be before (a range). */
    public const DATE = 'date';

    /** One of `options`. */
    public const SELECT = 'select';

    /** A whole number between `min` and `max`. */
    public const NUMBER = 'number';

    /** A card template (public id); the client lists the organization's templates. */
    public const TEMPLATE = 'template';

    /** A user of the organization (public id); the client lists them when it may. */
    public const USER = 'user';

    /** An item of a master data list (`masterData` type, public id), inside the `parent` filter's item if any. */
    public const MASTER_DATA = 'master_data';

    /**
     * @param  array<int, array{value: string, label: string}>  $options
     * @param  array<string, string>|null  $when  shown only while these other filters have these values (their default counts)
     */
    public function __construct(
        public string $key,
        public string $type,
        public string $label,
        public array $options = [],
        public ?string $masterData = null,
        public ?string $parent = null,
        public ?string $after = null,
        public ?int $min = null,
        public ?int $max = null,
        public ?string $default = null,
        public ?array $when = null,
    ) {}

    /**
     * @return array<int, mixed>
     */
    public function rules(): array
    {
        return match ($this->type) {
            self::SEARCH => ['nullable', 'string', 'max:100'],
            self::DATE => ['nullable', 'date_format:Y-m-d', ...($this->after ? ["after_or_equal:filter.{$this->after}"] : [])],
            self::SELECT => ['nullable', Rule::in(array_column($this->options, 'value'))],
            self::NUMBER => ['nullable', 'integer', "between:{$this->min},{$this->max}"],
            default => ['nullable', 'string', 'size:26'],
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'key' => $this->key,
            'type' => $this->type,
            'label' => $this->label,
            'options' => $this->options ?: null,
            'master_data' => $this->masterData,
            'parent' => $this->parent,
            'after' => $this->after,
            'min' => $this->min,
            'max' => $this->max,
            'default' => $this->default,
            'when' => $this->when,
        ], fn (mixed $value) => $value !== null);
    }
}
