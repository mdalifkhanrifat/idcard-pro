<?php

namespace App\Data\Cardholders;

/**
 * Listing criteria for cardholders.
 */
final readonly class CardholderFilter
{
    public const SORTS = ['full_name', 'employee_no', 'joining_date', 'created_at', 'updated_at'];

    /**
     * Master data filters: filter key => cardholder column.
     */
    public const LOOKUP_FILTERS = [
        'department' => 'department_id',
        'unit' => 'unit_id',
        'rank' => 'rank_id',
        'designation' => 'designation_id',
        'branch' => 'branch_id',
        'blood_group' => 'blood_group_id',
    ];

    /**
     * @param  array<string, string>  $lookups  cardholder column => master data public id
     */
    public function __construct(
        public ?string $search = null,
        public ?string $status = null,
        public ?string $employmentType = null,
        public array $lookups = [],
        public ?string $joinedFrom = null,
        public ?string $joinedTo = null,
        public string $sort = 'created_at',
        public bool $descending = true,
        public int $perPage = 15,
    ) {}

    /**
     * @param  array<string, mixed>  $input  validated request input
     */
    public static function fromArray(array $input): self
    {
        $filter = $input['filter'] ?? [];
        $sort = (string) ($input['sort'] ?? '-created_at');

        $lookups = [];
        foreach (self::LOOKUP_FILTERS as $key => $column) {
            if (filled($filter[$key] ?? null)) {
                $lookups[$column] = (string) $filter[$key];
            }
        }

        return new self(
            search: $filter['search'] ?? null,
            status: $filter['status'] ?? null,
            employmentType: $filter['employment_type'] ?? null,
            lookups: $lookups,
            joinedFrom: $filter['joined_from'] ?? null,
            joinedTo: $filter['joined_to'] ?? null,
            sort: ltrim($sort, '-'),
            descending: str_starts_with($sort, '-'),
            perPage: (int) ($input['per_page'] ?? 15),
        );
    }
}
