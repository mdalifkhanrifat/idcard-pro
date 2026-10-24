<?php

namespace App\Services\Cards;

use App\Models\Card;
use App\Models\Tenant;
use App\Repositories\Contracts\CardRepositoryInterface;
use App\Repositories\Contracts\NotificationOnceRepositoryInterface;
use App\Repositories\Contracts\TenantRepositoryInterface;
use App\Services\Notifications\Events\CardExpiringEvent;
use App\Services\Notifications\NotificationService;
use App\Services\Notifications\NotificationSettings;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;

/**
 * Reminders before a card's last day (5.7d), run every hour: for each organization that sends the card_expiring
 * event on some channel, from the morning hour of its own day (config cards.expiry_reminders.from_hour), every
 * current card of an active cardholder (the Expired report's "expiring" rule) whose last day less one of the
 * event's days (its own setting) has come — once (NotificationOnceRepository::claim, keyed by the days and the
 * last day), not when found more than `grace_days` late, and not for a card issued after that day (its holder just
 * got it). Several due at once for one card send the nearest only; the others count as done.
 */
class CardExpiryReminderService
{
    public const EVENT = 'card_expiring';

    public function __construct(
        private TenantRepositoryInterface $tenants,
        private CardRepositoryInterface $cards,
        private NotificationOnceRepositoryInterface $once,
        private CardNotifications $notifications,
        private TenantContext $tenantContext,
    ) {}

    /**
     * @return array<string, int> organization slug => reminders sent, for organizations that send them
     */
    public function send(?Tenant $only = null, ?CarbonImmutable $now = null): array
    {
        $now = ($now ?? CarbonImmutable::now())->utc();
        $sent = [];
        $each = function (Tenant $tenant) use ($now, &$sent): void {
            $count = $this->tenantContext->run($tenant, fn (): ?int => $this->sendFor($tenant, $now));
            if ($count !== null) {
                $sent[$tenant->slug] = $count;
            }
        };
        $only === null ? $this->tenants->each($each) : $each($only);

        return $sent;
    }

    /**
     * The organization's reminders due today, or null when it sends none.
     */
    private function sendFor(Tenant $tenant, CarbonImmutable $now): ?int
    {
        $settings = $tenant->notificationSettings();
        if (NotificationService::channelsFor($settings, self::EVENT) === []) {
            return null;
        }
        $local = $now->setTimezone($tenant->timezone ?: (string) config('app.timezone'));
        if ($local->hour < (int) config('cards.expiry_reminders.from_hour')) {
            return 0;
        }
        // The nearest first: with several due at once, the others are only marked done
        $days = (array) NotificationSettings::option($settings, self::EVENT, CardExpiringEvent::DAYS);
        sort($days);
        $today = CarbonImmutable::parse($local->toDateString(), 'UTC');
        $oldest = $today->subDays((int) config('cards.expiry_reminders.grace_days'));

        $count = 0;
        foreach ($this->cards->expiringBetween($today->toDateString(), $today->addDays((int) max($days))->toDateString()) as $card) {
            if ($this->remind($card, $days, $today, $oldest)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @param  array<int, int>  $days  ascending
     */
    private function remind(Card $card, array $days, CarbonImmutable $today, CarbonImmutable $oldest): bool
    {
        // Plain dates in one zone: the card's last day and issue day are the organization's dates
        $lastDay = CarbonImmutable::parse($card->expiry_date?->toDateString(), 'UTC');
        $issued = CarbonImmutable::parse($card->issue_date->toDateString(), 'UTC');
        $sent = false;
        foreach ($days as $before) {
            $due = $lastDay->subDays($before);
            if ($due->gt($today) || $due->lt($oldest) || $issued->gt($due)) {
                continue;
            }
            if ($this->once->claim($card->tenant_id, self::EVENT, $card->id, "{$before}:{$lastDay->toDateString()}") && ! $sent) {
                $this->notifications->expiring($card);
                $sent = true;
            }
        }

        return $sent;
    }
}
