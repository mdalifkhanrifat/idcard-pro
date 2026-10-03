<?php

namespace App\Data\Users;

/**
 * Listing criteria for tenant users.
 */
final readonly class UserFilter
{
    public const SORTS = ['name', 'email', 'last_login_at', 'created_at'];

    public function __construct(
        public ?string $search = null,
        public ?string $status = null,
        public ?string $role = null,
        public ?bool $twoFactor = null,
        public ?bool $locked = null,
        public ?string $createdFrom = null,
        public ?string $createdTo = null,
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
        $bool = fn (string $key): ?bool => isset($filter[$key]) && $filter[$key] !== ''
            ? filter_var($filter[$key], FILTER_VALIDATE_BOOL)
            : null;

        return new self(
            search: $filter['search'] ?? null,
            status: $filter['status'] ?? null,
            role: $filter['role'] ?? null,
            twoFactor: $bool('two_factor'),
            locked: $bool('locked'),
            createdFrom: $filter['created_from'] ?? null,
            createdTo: $filter['created_to'] ?? null,
            sort: ltrim($sort, '-'),
            descending: str_starts_with($sort, '-'),
            perPage: (int) ($input['per_page'] ?? 15),
        );
    }
}
