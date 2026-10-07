<?php

namespace App\Data\Cards;

/**
 * A PDF of issued cards: which sides, the page layout ("card" = one card per page, or a paper key from
 * config cards.print.sheets), and whether making it counts as printing them (method "pdf").
 */
final readonly class CardPdfData
{
    public function __construct(
        public string $sides = 'both',
        public bool $record = false,
        public ?string $note = null,
        public string $sheet = 'card',
    ) {}

    /**
     * @param  array<string, mixed>  $input  validated request input
     */
    public static function fromArray(array $input): self
    {
        $note = trim((string) ($input['note'] ?? ''));

        return new self(
            sides: $input['sides'] ?? 'both',
            record: (bool) ($input['record'] ?? false),
            note: $note === '' ? null : $note,
            sheet: $input['sheet'] ?? 'card',
        );
    }
}
