<?php

namespace App\Data\Cardholders;

use App\Models\Cardholder;

/**
 * One incoming person checked against the cardholder rules (CardholderSyncService::check()):
 * who it is (an existing cardholder found by employee number, or nobody yet), the input as it
 * will be saved, and what is wrong with it. Nothing has been written.
 */
final readonly class CardholderSyncCheck
{
    /**
     * @param  array<string, mixed>  $validated  the input as validated; empty when there are messages
     * @param  array<int, string>  $messages  in the reader's language, in the order they were found
     */
    public function __construct(
        public ?Cardholder $existing,
        public array $validated,
        public array $messages,
    ) {}

    public function passes(): bool
    {
        return $this->messages === [];
    }

    /**
     * @return 'created'|'updated'
     */
    public function action(): string
    {
        return $this->existing instanceof Cardholder ? 'updated' : 'created';
    }
}
