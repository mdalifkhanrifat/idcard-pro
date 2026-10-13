<?php

namespace App\Data\CardTemplates;

/**
 * Listing criteria for card templates.
 */
final readonly class CardTemplateFilter
{
    public function __construct(
        public ?string $search = null,
        public ?string $cardTypePublicId = null,
        public ?string $audience = null,
        public ?bool $isActive = null,
        /** Load each template's designer elements (for thumbnails). */
        public bool $withElements = false,
        /** The report's order and page size (the list page shows every template, active first). */
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

        return new self(
            search: $filter['search'] ?? null,
            cardTypePublicId: $filter['card_type'] ?? null,
            audience: $filter['audience'] ?? null,
            isActive: isset($filter['is_active']) && $filter['is_active'] !== '' ? filter_var($filter['is_active'], FILTER_VALIDATE_BOOL) : null,
            withElements: ($input['include'] ?? null) === 'elements',
            sort: ltrim((string) ($input['sort'] ?? 'name'), '-'),
            descending: str_starts_with((string) ($input['sort'] ?? 'name'), '-'),
            perPage: (int) ($input['per_page'] ?? 15),
        );
    }
}
