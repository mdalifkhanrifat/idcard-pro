<?php

namespace App\Repositories\Contracts;

use App\Data\Cards\CardFilter;
use App\Models\Card;
use App\Models\Cardholder;
use App\Models\CardTemplate;
use DateTimeInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * @extends RepositoryInterface<Card>
 */
interface CardRepositoryInterface extends RepositoryInterface
{
    /**
     * @return LengthAwarePaginator<int, Card>
     */
    public function list(CardFilter $filter): LengthAwarePaginator;

    /**
     * Public ids of the cards the list filter finds that may be printed (active, not expired), in the list's
     * order and at most `$limit`, with how many such cards there are in all.
     *
     * @return array{ids: array<int, string>, total: int}
     */
    public function printablePublicIds(CardFilter $filter, int $limit): array;

    /**
     * Counts for the cards overview: active, expired (active past expiry), expiring within the given days,
     * revoked, and pending (valid but never printed).
     *
     * @return array{active: int, expired: int, expiring: int, revoked: int, pending: int}
     */
    public function summary(int $expiringDays): array;

    /**
     * Every card of a cardholder and their family members, newest first.
     *
     * @return Collection<int, Card>
     */
    public function forCardholder(Cardholder $cardholder): Collection;

    /**
     * The holder's active card, locked for the issuing transaction.
     */
    public function activeForHolder(string $holderKey, bool $lock = false): ?Card;

    /**
     * The holder's latest card from a template with this code (for keeping the number on reissue).
     */
    public function latestForHolderWithCode(int $cardholderId, ?int $familyMemberId, string $code): ?Card;

    /**
     * Whether another holder's active card carries this RF card number.
     */
    public function rfCardInUse(string $rfCardNo, string $exceptHolderKey): bool;

    /**
     * Template image files that issued cards of this template still print (one snapshot per template version).
     *
     * @return array<int, string>
     */
    public function snapshotImagePaths(CardTemplate $template): array;

    /**
     * Record the shared things (library images, fonts) a card's design uses.
     *
     * @param  array<string, array<int, string>>  $assets  kind => references
     */
    public function recordAssets(Card $card, array $assets): void;

    public function usesAsset(string $kind, string $reference): bool;

    public function existsForCardholder(int $cardholderId): bool;

    public function existsForFamilyMember(int $familyMemberId): bool;

    public function existsForTemplate(int $templateId): bool;

    /**
     * Whether a card printed this photo or signature file.
     */
    public function usesFile(int $fileId): bool;

    public function usesSignatory(int $signatoryId): bool;

    /**
     * The card with this verification code, in any organization (public verification has no signed-in
     * organization), with its organization and template.
     */
    public function findByVerifyCode(string $code): ?Card;

    /**
     * Cards by public id in the order given, with what drawing them needs. Unknown ids are left out.
     *
     * @param  array<int, string>  $publicIds
     * @return Collection<int, Card>
     */
    public function byPublicIds(array $publicIds): Collection;

    /**
     * Cards by public id, locked for a printing transaction, in the order given. Unknown ids are left out.
     *
     * @param  array<int, string>  $publicIds
     * @return Collection<int, Card>
     */
    public function lockedByPublicIds(array $publicIds): Collection;

    /**
     * Add printed copies to the cards' running totals.
     *
     * @param  array<int, int>  $cardIds
     */
    public function addPrints(array $cardIds, int $copies, DateTimeInterface $printedAt): void;
}
