<?php

namespace App\Data\Signatories;

/**
 * Criteria for signatories: the list page's (search, active) and the report's (authority type, order, size).
 */
final readonly class SignatoryFilter
{
    public const SORTS = ['name', 'designation', 'effective_from', 'effective_to', 'created_at'];

    public function __construct(
        /** Name (either language) or designation. */
        public ?string $search = null,
        public ?bool $isActive = null,
        public ?string $authorityTypePublicId = null,
        public string $sort = 'name',
        public bool $descending = false,
        public int $perPage = 15,
    ) {}

    /**
     * @param  array<string, mixed>  $input  validated input
     */
    public static function fromArray(array $input): self
    {
        $filter = $input['filter'] ?? [];
        $sort = (string) ($input['sort'] ?? 'name');

        return new self(
            search: $filter['search'] ?? null,
            isActive: isset($filter['is_active']) && $filter['is_active'] !== '' ? filter_var($filter['is_active'], FILTER_VALIDATE_BOOL) : null,
            authorityTypePublicId: $filter['authority_type'] ?? null,
            sort: ltrim($sort, '-'),
            descending: str_starts_with($sort, '-'),
            perPage: (int) ($input['per_page'] ?? 15),
        );
    }
}
