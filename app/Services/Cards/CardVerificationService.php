<?php

namespace App\Services\Cards;

use App\Enums\CardStatus;
use App\Models\Card;
use App\Models\Tenant;
use App\Repositories\Contracts\CardRepositoryInterface;
use App\Repositories\Contracts\CardVerificationRepositoryInterface;
use App\Support\Tenancy\TenantContext;

/**
 * Public card verification (3.7): anyone holding a card (or its QR code) can check whether it is valid,
 * without signing in. Each organization decides whether this is on and which details the page shows
 * (tenants.settings.verification); details come from the card as issued, the status is live. An unknown
 * code, an organization with verification off and a suspended organization all look the same (null).
 * Checks are logged for the organization (unless it turned that off), without keeping addresses.
 */
class CardVerificationService
{
    public function __construct(
        private CardRepositoryInterface $cards,
        private CardDataResolver $cardData,
        private TenantContext $tenantContext,
        private CardVerificationRepositoryInterface $checks,
    ) {}

    /**
     * @return array<string, mixed>|null
     */
    /**
     * @param  string|null  $address  the checking device's IP address, only to tell devices apart in the log
     */
    public function verify(string $code, ?string $address = null): ?array
    {
        $code = strtolower($code);
        if (preg_match('/^[a-z0-9]{'.(int) config('cards.verification.code_length').'}$/', $code) !== 1) {
            return null;
        }
        $card = $this->cards->findByVerifyCode($code);
        $tenant = $card?->tenant;
        if ($card === null || $tenant === null || ! $tenant->isActive()) {
            return null;
        }
        $settings = $tenant->verificationSettings();
        if (! $settings['enabled']) {
            return null;
        }

        return $this->tenantContext->run($tenant, function () use ($card, $tenant, $settings, $address): array {
            $values = $this->cardData->forCard($card, withBiometrics: false);
            $status = $this->status($card);
            if ($settings['log_checks']) {
                $this->log($card, $tenant, $status, $address);
            }
            $details = [];
            foreach ($settings['fields'] as $key) {
                $value = $key === 'template' ? $card->template->name : (string) ($values['fields'][$key] ?? '');
                if ($key !== 'photo' && $value !== '') {
                    $details[] = ['key' => $key, 'value' => $value];
                }
            }

            return [
                'status' => $status,
                'status_changed_at' => $status === 'revoked' || $status === 'replaced' ? $card->status_changed_at?->toIso8601String() : null,
                'revoke_reason' => $status === 'revoked' && $settings['show_revoke_reason'] ? $card->status_note : null,
                'holder' => $card->family_member_id === null ? 'cardholder' : 'family',
                'photo_url' => in_array('photo', $settings['fields'], true) ? $values['images']['photo'] : null,
                'details' => $details,
                'organization' => [
                    'name' => $tenant->name,
                    'logo_url' => $tenant->logo_path ? url('storage/'.$tenant->logo_path) : null,
                    'primary_color' => $tenant->primary_color,
                ],
                'note' => $settings['note'],
                'checked_at' => now()->toIso8601String(),
            ];
        });
    }

    /**
     * The card's checks for its details: newest first, how many in all and from how many devices.
     *
     * @return array{checks: array<int, array{status: string, device: string, verified_at: string|null}>, total: int, devices: int}
     */
    public function history(Card $card): array
    {
        return [
            'checks' => $this->checks->recentFor($card, (int) config('cards.verification.history_limit'))
                ->map(fn ($check) => ['status' => $check->status, 'device' => substr($check->device, 0, 6), 'verified_at' => $check->verified_at->toIso8601String()])
                ->all(),
            'total' => $card->verify_count,
            'devices' => $this->checks->devicesFor($card),
        ];
    }

    /**
     * Record a check, once per device within a short time (a refresh is not a new check).
     */
    private function log(Card $card, Tenant $tenant, string $status, ?string $address): void
    {
        $device = substr(hash_hmac('sha256', $tenant->id.'|'.($address ?? ''), (string) config('app.key')), 0, 16);
        $now = now();
        if ($this->checks->checkedSince($card, $device, $now->copy()->subSeconds((int) config('cards.verification.log_repeat_seconds')))) {
            return;
        }
        $this->checks->create(['card_id' => $card->id, 'status' => $status, 'device' => $device, 'verified_at' => $now]);
        $this->cards->addVerification($card, $now);
    }

    /**
     * valid: active and not expired; expired: active past its expiry; revoked; replaced (a newer card exists).
     */
    private function status(Card $card): string
    {
        return match (true) {
            $card->status === CardStatus::Revoked => 'revoked',
            $card->status === CardStatus::Replaced => 'replaced',
            $card->isExpired() => 'expired',
            default => 'valid',
        };
    }
}
