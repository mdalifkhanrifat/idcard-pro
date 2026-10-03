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
        $date ??= now();
        $format = $this->format();

        return DB::transaction(fn (): string => $format->render($code, $date, $this->sequences->increment($format->prefix($code, $date))));
    }

    /**
     * What the next number would look like, without reserving it.
     */
    public function preview(string $code, ?string $pattern = null, ?CarbonInterface $date = null): string
    {
        $date ??= now();
        $format = $pattern !== null ? new CardNumberFormat($pattern) : $this->format();

        return $format->render($code, $date, $this->sequences->peekNext($format->prefix($code, $date)));
    }
}
