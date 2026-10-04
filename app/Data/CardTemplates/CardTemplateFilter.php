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
        );
    }
}
