<?php

namespace App\Data\Cards;

/**
 * How cards were printed: the method (config/cards.php → print.methods), copies per card and an optional note.
 */
final readonly class PrintCardsData
{
    public function __construct(
        public string $method,
        public int $copies = 1,
        public ?string $note = null,
        /** Print although something the organization requires is missing (cards.override_requirements). */
        public ?string $overrideReason = null,
    ) {}

    /**
     * @param  array<string, mixed>  $input  validated request input
     */
    public static function fromArray(array $input): self
    {
        $note = trim((string) ($input['note'] ?? ''));

        return new self(
            method: $input['method'] ?? 'browser',
            copies: (int) ($input['copies'] ?? 1),
            note: $note === '' ? null : $note,
            overrideReason: trim((string) ($input['override_reason'] ?? '')) ?: null,
        );
    }
}
