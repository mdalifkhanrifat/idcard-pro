<?php

namespace App\Data\Tenants;

/**
 * Listing criteria for organizations (platform administration).
 */
final readonly class TenantFilter
{
    public const SORTS = ['name', 'created_at'];

    public function __construct(
        public ?string $name = null,
        public ?string $slug = null,
        public ?string $status = null,
        public string $sort = 'name',
        public bool $descending = false,
        public int $perPage = 15,
    ) {}

    /**
     * @param  array<string, mixed>  $input  validated request input
     */
    public static function fromArray(array $input): self
    {
        $filter = $input['filter'] ?? [];
        $sort = (string) ($input['sort'] ?? 'name');

        return new self(
            name: $filter['name'] ?? null,
            slug: $filter['slug'] ?? null,
            status: $filter['status'] ?? null,
            sort: ltrim($sort, '-'),
            descending: str_starts_with($sort, '-'),
            perPage: (int) ($input['per_page'] ?? 15),
        );
    }
}
