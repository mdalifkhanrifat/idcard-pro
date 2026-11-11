<?php

namespace App\Data\Cardholders;

use App\Enums\CardholderSyncKind;

/**
 * Which HR sync runs to list: of one kind, of one account only (an HR system sees its own), a page at a time.
 */
final readonly class CardholderSyncFilter
{
    public function __construct(
        public ?CardholderSyncKind $kind = null,
        public ?int $userId = null,
        public int $perPage = 15,
    ) {}

    /**
     * @param  array<string, mixed>  $input  validated request input
     */
    public static function fromArray(array $input, ?int $userId = null): self
    {
        $filter = (array) ($input['filter'] ?? []);

        return new self(
            kind: CardholderSyncKind::tryFrom((string) ($filter['kind'] ?? '')),
            userId: $userId,
            perPage: (int) ($input['per_page'] ?? 15),
        );
    }
}
