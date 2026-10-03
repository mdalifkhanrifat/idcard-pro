<?php

namespace App\Data\MasterData;

/**
 * Listing criteria for master data items.
 */
final readonly class MasterDataFilter
{
    public const SORTS = ['sort_order', 'name', 'code', 'updated_at'];

    /**
     * @param  int|null  $perPage  null returns every matching item (for dropdowns)
     */
    public function __construct(
        public ?string $search = null,
        public ?string $parentPublicId = null,
        public ?bool $isActive = null,
        public string $sort = 'sort_order',
        public bool $descending = false,
        public ?int $perPage = 25,
    ) {}

    /**
     * @param  array<string, mixed>  $input  validated request input
     */
    public static function fromArray(array $input): self
    {
        $sort = (string) ($input['sort'] ?? 'sort_order');
        $filter = $input['filter'] ?? [];

        return new self(
            search: $filter['search'] ?? null,
            parentPublicId: $filter['parent'] ?? null,
            isActive: isset($filter['is_active']) ? filter_var($filter['is_active'], FILTER_VALIDATE_BOOL) : null,
            sort: ltrim($sort, '-'),
            descending: str_starts_with($sort, '-'),
            perPage: ($input['per_page'] ?? null) === 'all' ? null : (int) ($input['per_page'] ?? 25),
        );
    }
}
