<?php

namespace App\Repositories\Eloquent;

use App\Models\Card;
use App\Models\CardAsset;
use App\Models\Cardholder;
use App\Models\CardTemplate;
use App\Repositories\Contracts\CardRepositoryInterface;
use Illuminate\Support\Collection;

/**
 * @extends EloquentRepository<Card>
 */
class CardRepository extends EloquentRepository implements CardRepositoryInterface
{
    protected function model(): string
    {
        return Card::class;
    }

    public function forCardholder(Cardholder $cardholder): Collection
    {
        return $this->query()
            ->where('cardholder_id', $cardholder->id)
            ->with(['template:id,public_id,name,code', 'familyMember:id,public_id,name,relation', 'issuer:id,name', 'replacedBy:id,public_id,number', 'document'])
            ->latest('id')
            ->get();
    }

    public function activeForHolder(string $holderKey, bool $lock = false): ?Card
    {
        return $this->query()->where('active_holder_key', $holderKey)->when($lock, fn ($q) => $q->lockForUpdate())->first();
    }

    public function latestForHolderWithCode(int $cardholderId, ?int $familyMemberId, string $code): ?Card
    {
        return $this->query()
            ->select('cards.*')
            ->join('card_templates', 'card_templates.id', '=', 'cards.card_template_id')
            ->where('cards.cardholder_id', $cardholderId)
            ->where('cards.family_member_id', $familyMemberId)
            ->where('card_templates.code', $code)
            ->latest('cards.id')
            ->first();
    }

    public function rfCardInUse(string $rfCardNo, string $exceptHolderKey): bool
    {
        return $this->query()
            ->where('rf_card_no', $rfCardNo)
            ->whereNotNull('active_holder_key')
            ->where('active_holder_key', '!=', $exceptHolderKey)
            ->exists();
    }

    public function snapshotImagePaths(CardTemplate $template): array
    {
        // Cards of one template version share the same layout, so one snapshot per version is enough
        $ids = $this->query()->where('card_template_id', $template->id)->groupBy('template_version')->selectRaw('min(id) as id')->pluck('id');

        return $this->query()->whereKey($ids)->get(['id', 'layout'])
            ->flatMap(fn (Card $card) => array_column($card->layout['elements'] ?? [], 'image_path'))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    public function recordAssets(Card $card, array $assets): void
    {
        foreach ($assets as $kind => $references) {
            foreach (array_unique($references) as $reference) {
                CardAsset::query()->create(['card_id' => $card->id, 'kind' => $kind, 'reference' => $reference]);
            }
        }
    }

    public function usesAsset(string $kind, string $reference): bool
    {
        return CardAsset::query()->where('kind', $kind)->where('reference', $reference)->exists();
    }

    public function existsForCardholder(int $cardholderId): bool
    {
        return $this->query()->where('cardholder_id', $cardholderId)->exists();
    }

    public function existsForFamilyMember(int $familyMemberId): bool
    {
        return $this->query()->where('family_member_id', $familyMemberId)->exists();
    }

    public function existsForTemplate(int $templateId): bool
    {
        return $this->query()->where('card_template_id', $templateId)->exists();
    }

    public function usesFile(int $fileId): bool
    {
        return $this->query()->where(fn ($q) => $q->where('photo_file_id', $fileId)->orWhere('signature_file_id', $fileId))->exists();
    }

    public function usesSignatory(int $signatoryId): bool
    {
        return $this->query()->where('signatory_id', $signatoryId)->exists();
    }
}
