<?php

namespace App\Data\Integrations;

/**
 * Listing criteria for an organization's integration accounts.
 */
final readonly class IntegrationFilter
{
    public const SORTS = ['name', 'created_at'];

    public function __construct(
        public ?string $search = null,
        public ?string $status = null,
        public ?string $role = null,
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
            search: $filter['search'] ?? null,
            status: $filter['status'] ?? null,
            role: $filter['role'] ?? null,
            sort: ltrim($sort, '-'),
            descending: str_starts_with($sort, '-'),
            perPage: (int) ($input['per_page'] ?? 15),
        );
    }
}
