<?php

namespace App\Services\Cards;

use App\Enums\CardStatus;
use App\Models\Card;
use App\Repositories\Contracts\CardRepositoryInterface;
use App\Support\Tenancy\TenantContext;

/**
 * Public card verification (3.7): anyone holding a card (or its QR code) can check whether it is valid,
 * without signing in. Each organization decides whether this is on and which details the page shows
 * (tenants.settings.verification); details come from the card as issued, the status is live. An unknown
 * code, an organization with verification off and a suspended organization all look the same (null).
 */
class CardVerificationService
{
    public function __construct(
        private CardRepositoryInterface $cards,
        private CardDataResolver $cardData,
        private TenantContext $tenantContext,
    ) {}

    /**
     * @return array<string, mixed>|null
     */
    public function verify(string $code): ?array
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

        return $this->tenantContext->run($tenant, function () use ($card, $tenant, $settings): array {
            $values = $this->cardData->forCard($card);
            $status = $this->status($card);
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
