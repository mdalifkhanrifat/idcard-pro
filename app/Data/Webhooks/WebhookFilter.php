<?php

namespace App\Data\Webhooks;

/**
 * Listing criteria for an organization's webhooks.
 */
final readonly class WebhookFilter
{
    public const SORTS = ['name', 'created_at'];

    public function __construct(
        public ?string $search = null,
        public ?bool $enabled = null,
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
        $enabled = $filter['enabled'] ?? null;

        return new self(
            search: $filter['search'] ?? null,
            enabled: $enabled === null || $enabled === '' ? null : filter_var($enabled, FILTER_VALIDATE_BOOL),
            sort: ltrim($sort, '-'),
            descending: str_starts_with($sort, '-'),
            perPage: (int) ($input['per_page'] ?? 15),
        );
    }
}
