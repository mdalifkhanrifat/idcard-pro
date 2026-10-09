<?php

namespace App\Repositories\Eloquent;

use App\Data\Cards\CardFilter;
use App\Enums\CardStatus;
use App\Models\Card;
use App\Models\CardAsset;
use App\Models\Cardholder;
use App\Models\CardTemplate;
use App\Models\MasterDataItem;
use App\Repositories\Contracts\CardRepositoryInterface;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
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

    public function list(CardFilter $filter): LengthAwarePaginator
    {
        return $this->filtered($filter)
            ->with(['template:id,public_id,name,code', 'familyMember:id,public_id,name,relation', 'cardholder:id,public_id,full_name,employee_no,department_id', 'cardholder.department', 'issuer:id,name'])
            ->paginate(min($filter->perPage, 100))
            ->withQueryString();
    }

    public function printablePublicIds(CardFilter $filter, int $limit): array
    {
        $query = $this->whereValid($this->filtered($filter));

        return ['ids' => (clone $query)->limit($limit)->pluck('public_id')->all(), 'total' => $query->count()];
    }

    /**
     * The list's criteria and order, shared by the list and everything that acts on "all cards it finds".
     *
     * @return Builder<Card>
     */
    private function filtered(CardFilter $filter): Builder
    {
        $today = today()->toDateString();
        $query = $this->query()
            ->when($filter->search, fn (Builder $q, string $search) => $q->where(fn (Builder $w) => $w
                ->where('number', 'like', "%{$search}%")
                ->orWhere('rf_card_no', 'like', "%{$search}%")
                ->orWhereHas('cardholder', fn (Builder $c) => $c->where('full_name', 'like', "%{$search}%")
                    ->orWhere('full_name_local', 'like', "%{$search}%")
                    ->orWhere('employee_no', 'like', "%{$search}%"))
                ->orWhereHas('familyMember', fn (Builder $f) => $f->where('name', 'like', "%{$search}%"))))
            ->when($filter->status, fn (Builder $q, string $status) => $q->where('status', $status))
            ->when($filter->reason, fn (Builder $q, string $reason) => $q->where('reason', $reason))
            ->when($filter->templatePublicId, fn (Builder $q, string $id) => $q->whereHas('template', fn (Builder $t) => $t->where('public_id', $id)))
            ->when($filter->holder === 'family', fn (Builder $q) => $q->whereNotNull('family_member_id'))
            ->when($filter->holder === 'cardholder', fn (Builder $q) => $q->whereNull('family_member_id'))
            ->when($filter->issuedFrom, fn (Builder $q, string $date) => $q->whereDate('issue_date', '>=', $date))
            ->when($filter->issuedTo, fn (Builder $q, string $date) => $q->whereDate('issue_date', '<=', $date))
            ->when($filter->validity === 'expired', fn (Builder $q) => $q->where('status', CardStatus::Active)->whereDate('expiry_date', '<', $today))
            ->when($filter->validity === 'expiring', fn (Builder $q) => $q->where('status', CardStatus::Active)
                ->whereDate('expiry_date', '>=', $today)
                ->whereDate('expiry_date', '<=', today()->addDays($filter->expiringDays)->toDateString()))
            ->when($filter->validity === 'valid', fn (Builder $q) => $this->whereValid($q))
            ->when($filter->printed === 'pending', fn (Builder $q) => $q->where('print_count', 0))
            ->when($filter->printed === 'printed', fn (Builder $q) => $q->where('print_count', '>', 0))
            ->when($filter->printedFrom || $filter->printedTo, fn (Builder $q) => $q->whereHas('prints', fn (Builder $p) => $p
                ->when($filter->printedFrom, fn (Builder $w, string $date) => $w->where('printed_at', '>=', $this->dayStart($date)))
                ->when($filter->printedTo, fn (Builder $w, string $date) => $w->where('printed_at', '<', $this->dayStart($date)->addDay()))));

        foreach ($filter->lookups as $column => $publicId) {
            $query->whereHas('cardholder', fn (Builder $c) => $c->whereIn($column, MasterDataItem::query()->where('public_id', $publicId)->select('id')));
        }

        return $query
            ->orderBy($filter->sort, $filter->descending ? 'desc' : 'asc')
            ->orderBy('id', 'desc');
    }

    /**
     * Active cards not past their expiry: the cards that may be printed (CardPrintService::printable()).
     *
     * @param  Builder<Card>  $query
     * @return Builder<Card>
     */
    private function whereValid(Builder $query): Builder
    {
        return $query->where('status', CardStatus::Active)
            ->where(fn (Builder $w) => $w->whereNull('expiry_date')->orWhereDate('expiry_date', '>=', today()->toDateString()));
    }

    public function summary(int $expiringDays): array
    {
        $today = today()->toDateString();
        $active = fn () => $this->query()->where('status', CardStatus::Active);

        return [
            'active' => $active()->count(),
            'expired' => $active()->whereDate('expiry_date', '<', $today)->count(),
            'expiring' => $active()->whereDate('expiry_date', '>=', $today)->whereDate('expiry_date', '<=', today()->addDays($expiringDays)->toDateString())->count(),
            'revoked' => $this->query()->where('status', CardStatus::Revoked)->count(),
            // Valid cards never printed (legacy "PENDING")
            'pending' => $this->whereValid($this->query())->where('print_count', 0)->count(),
        ];
    }

    /**
     * Start of a day in the organization's time zone, as stored (UTC).
     */
    private function dayStart(string $date): CarbonImmutable
    {
        $timezone = app(TenantContext::class)->get()?->timezone ?: (string) config('app.timezone');

        return CarbonImmutable::parse($date, $timezone)->startOfDay()->utc();
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

    public function numberInUse(string $number, string $exceptHolderKey): bool
    {
        return $this->query()
            ->where('active_number_key', $number)
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

    public function existsForHolder(int $cardholderId, ?int $familyMemberId): bool
    {
        return $this->query()->where('cardholder_id', $cardholderId)->where('family_member_id', $familyMemberId)->exists();
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

    public function findByVerifyCode(string $code): ?Card
    {
        return Card::query()->withoutGlobalScopes()->where('verify_code', $code)->with(['tenant', 'template'])->first();
    }

    public function byPublicIds(array $publicIds): Collection
    {
        $cards = $this->query()->whereIn('public_id', $publicIds)->with(['template', 'photo', 'signature', 'signatory'])->get()->keyBy('public_id');

        return collect($publicIds)->map(fn (string $id) => $cards->get($id))->filter()->values();
    }

    public function lockedByPublicIds(array $publicIds): Collection
    {
        $cards = $this->query()->whereIn('public_id', $publicIds)->orderBy('id')->lockForUpdate()->get()->keyBy('public_id');

        return collect($publicIds)->map(fn (string $id) => $cards->get($id))->filter()->values();
    }

    public function addPrints(array $cardIds, int $copies, DateTimeInterface $printedAt): void
    {
        $this->query()->whereKey($cardIds)->increment('print_count', $copies, ['last_printed_at' => $printedAt]);
    }

    public function addVerification(Card $card, DateTimeInterface $verifiedAt): void
    {
        Card::query()->withoutGlobalScopes()->whereKey($card->id)->increment('verify_count', 1, ['last_verified_at' => $verifiedAt]);
    }
}
