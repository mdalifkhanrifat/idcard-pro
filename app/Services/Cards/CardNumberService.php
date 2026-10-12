<?php

namespace App\Services\Cards;

use App\Repositories\Contracts\CardSequenceRepositoryInterface;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Issues card numbers from the tenant's format. Numbers are taken from a locked counter,
 * so two cards can never receive the same number (a legacy bug).
 */
class CardNumberService
{
    public function __construct(
        private CardSequenceRepositoryInterface $sequences,
        private TenantContext $tenantContext,
    ) {}

    public function format(): CardNumberFormat
    {
        return new CardNumberFormat((string) ($this->tenantContext->get()?->settings['card_number_format'] ?? CardNumberFormat::DEFAULT));
    }

    /**
     * Reserve the next number. Call inside the transaction that saves the card so an
     * unused number is released if saving fails.
     */
    public function next(string $code, ?CarbonInterface $date = null): string
    {
        // The organization's today: a number's year changes at its midnight, not UTC's
        $date ??= $this->tenantContext->today();
        $format = $this->format();

        return DB::transaction(fn (): string => $format->render($code, $date, $this->sequences->increment($format->prefix($code, $date))));
    }

    /**
     * A number given from outside (e.g. an import) is kept as it is; when it follows the organization's
     * format for this code, its counter moves past it so the sequence never hands it out again.
     */
    public function claim(string $number, string $code): void
    {
        $parsed = $this->format()->parse($number, $code);

        if ($parsed !== null) {
            DB::transaction(fn () => $this->sequences->advanceTo($parsed['key'], $parsed['sequence']));
        }
    }

    /**
     * What the next number would look like, without reserving it.
     */
    public function preview(string $code, ?string $pattern = null, ?CarbonInterface $date = null): string
    {
        // The organization's today: a number's year changes at its midnight, not UTC's
        $date ??= $this->tenantContext->today();
        $format = $pattern !== null ? new CardNumberFormat($pattern) : $this->format();

        return $format->render($code, $date, $this->sequences->peekNext($format->prefix($code, $date)));
    }
}
