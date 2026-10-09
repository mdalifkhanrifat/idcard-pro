<?php

namespace App\Data\Cards;

use Carbon\CarbonImmutable;

/**
 * A card that already exists outside this system (legacy import CSV: card no, issue date,
 * last print date/time, notes), brought in with its cardholder.
 */
final readonly class ImportCardData
{
    public function __construct(
        /** Kept as given; null: a new number from the organization's sequence. */
        public ?string $number = null,
        /** Null: today. */
        public ?CarbonImmutable $issueDate = null,
        /** Null: from the template's validity (no expiry when it has none). */
        public ?CarbonImmutable $expiryDate = null,
        public ?string $rfCardNo = null,
        public ?string $notes = null,
        /** When it was last printed; null: not printed yet (the card waits for printing). */
        public ?CarbonImmutable $lastPrintedAt = null,
    ) {}

    /**
     * The row carries no card at all, only the cardholder.
     */
    public function isEmpty(): bool
    {
        return $this->number === null && $this->issueDate === null && $this->expiryDate === null
            && $this->rfCardNo === null && $this->notes === null && $this->lastPrintedAt === null;
    }
}
