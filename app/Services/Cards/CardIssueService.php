<?php

namespace App\Services\Cards;

use App\Data\Cards\ImportCardData;
use App\Data\Cards\IssueCardData;
use App\Enums\CardStatus;
use App\Enums\TemplateAudience;
use App\Models\Card;
use App\Models\CardAsset;
use App\Models\Cardholder;
use App\Models\CardPrint;
use App\Models\CardTemplate;
use App\Models\FamilyMember;
use App\Models\User;
use App\Repositories\Contracts\CardholderFileRepositoryInterface;
use App\Repositories\Contracts\CardRepositoryInterface;
use App\Repositories\Contracts\CardTemplateRepositoryInterface;
use App\Repositories\Contracts\FamilyMemberRepositoryInterface;
use App\Repositories\Contracts\SignatoryRepositoryInterface;
use App\Services\Cardholders\CardholderFileService;
use App\Services\CardTemplates\CardTemplateDesignService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Issuing cards (legacy card form + "Reason For Print"). Every issue is a new card; the holder's
 * previous active card becomes "replaced". The number, design, photo, signatures and signatory
 * used are fixed on the card, so it always prints the same and matches its record.
 */
class CardIssueService
{
    public function __construct(
        private CardRepositoryInterface $cards,
        private CardTemplateRepositoryInterface $templates,
        private FamilyMemberRepositoryInterface $familyMembers,
        private CardholderFileRepositoryInterface $files,
        private SignatoryRepositoryInterface $signatories,
        private CardNumberService $numbers,
        private CardTemplateDesignService $design,
        private CardholderFileService $fileService,
        private CardDataResolver $cardData,
        private CardPrintService $printing,
    ) {}

    /**
     * @return Collection<int, Card>
     */
    public function history(Cardholder $cardholder): Collection
    {
        return $this->cards->forCardholder($cardholder);
    }

    public function issue(Cardholder $cardholder, IssueCardData $data, User $actor): Card
    {
        [$template, $member] = $this->resolveTarget($cardholder, $data->templateId, $data->familyMemberId);
        $this->checkReason($data);
        $issueDate = $data->issueDate ?? CarbonImmutable::today();
        $expiryDate = $this->expiryDate($template, $issueDate, $data->expiryDate);
        if ($expiryDate !== null && $expiryDate->lt($issueDate)) {
            throw ValidationException::withMessages(['expiry_date' => __('cards.issue.expiry_before_issue')]);
        }
        $holderKey = Card::holderKey($cardholder->id, $member?->id);
        if ($data->rfCardNo !== null && $this->cards->rfCardInUse($data->rfCardNo, $holderKey)) {
            throw ValidationException::withMessages(['rf_card_no' => __('cards.issue.rf_in_use')]);
        }
        $documentId = $this->documentId($cardholder, $data, $actor);

        return DB::transaction(function () use ($cardholder, $template, $member, $data, $issueDate, $expiryDate, $holderKey, $documentId, $actor): Card {
            $current = $this->cards->activeForHolder($holderKey, lock: true);

            return $this->createCard($cardholder, $member, $template, $current, [
                'number' => $this->number($cardholder, $member, $template, $issueDate),
                'rf_card_no' => $data->rfCardNo,
                'reason' => $data->reason,
                'notes' => $data->notes,
                'document_file_id' => $documentId,
                'issue_date' => $issueDate,
                'expiry_date' => $expiryDate,
                'issued_by' => $actor->id,
            ]);
        });
    }

    /**
     * What stops a card from being imported for this cardholder (a new cardholder: null, no cards yet), and
     * whether it is skipped because the holder's active card already has its number (importing again is harmless).
     *
     * @return array{errors: array<int, string>, skip: bool}
     */
    public function checkImport(?Cardholder $cardholder, CardTemplate $template, ImportCardData $data): array
    {
        $holderKey = $cardholder === null ? '' : Card::holderKey($cardholder->id, null);
        $current = $cardholder === null ? null : $this->cards->activeForHolder($holderKey);
        if ($current !== null && $data->number !== null && $current->number === $data->number) {
            return ['errors' => [], 'skip' => true];
        }

        $errors = [];
        if (! $template->is_active || $template->audience === TemplateAudience::FamilyMember) {
            $errors[] = __('cards.import.template_unavailable');
        }
        $issueDate = $data->issueDate ?? CarbonImmutable::today();
        $expiryDate = $this->expiryDate($template, $issueDate, $data->expiryDate);
        if ($expiryDate !== null && $expiryDate->lt($issueDate)) {
            $errors[] = __('cards.issue.expiry_before_issue');
        }
        if ($data->lastPrintedAt !== null) {
            // Calendar dates, the printing's in the organization's time zone
            $printedOn = $data->lastPrintedAt->setTimezone($template->tenant->timezone ?? 'UTC')->toDateString();
            if ($data->lastPrintedAt->isFuture()) {
                $errors[] = __('cards.import.printed_in_future');
            } elseif ($printedOn < $issueDate->toDateString()) {
                $errors[] = __('cards.import.printed_before_issue');
            }
        }
        if ($data->number !== null && $this->cards->numberInUse($data->number, $holderKey)) {
            $errors[] = __('cards.import.number_in_use', ['number' => $data->number]);
        }
        if ($data->rfCardNo !== null && $this->cards->rfCardInUse($data->rfCardNo, $holderKey)) {
            $errors[] = __('cards.issue.rf_in_use');
        }
        // Older card data must not replace a card issued after it
        if ($current !== null && $current->issue_date->gt($issueDate)) {
            $errors[] = __('cards.import.newer_card', ['number' => $current->number, 'date' => $current->issue_date->toDateString()]);
        }

        return ['errors' => $errors, 'skip' => false];
    }

    /**
     * Bring in a card that exists outside this system (an import row), like an issue: the number kept as
     * given (or a new one when there is none), the design and data fixed from today's template and cardholder,
     * and the holder's other active card replaced. A known last printing is logged, so the card counts as
     * printed; without one it waits for printing. Null when the holder already has this card.
     *
     * @throws ValidationException What checkImport() found
     */
    public function importCard(Cardholder $cardholder, CardTemplate $template, ImportCardData $data, ?User $actor): ?Card
    {
        $check = $this->checkImport($cardholder, $template, $data);
        if ($check['errors'] !== []) {
            throw ValidationException::withMessages(['card' => $check['errors']]);
        }
        if ($check['skip']) {
            return null;
        }
        $issueDate = $data->issueDate ?? CarbonImmutable::today();

        return DB::transaction(function () use ($cardholder, $template, $data, $issueDate, $actor): Card {
            $current = $this->cards->activeForHolder(Card::holderKey($cardholder->id, null), lock: true);
            if ($data->number !== null) {
                $this->numbers->claim($data->number, $template->code);
            }

            $card = $this->createCard($cardholder, null, $template, $current, [
                'number' => $data->number ?? $this->numbers->next($template->code, $issueDate),
                'rf_card_no' => $data->rfCardNo,
                'reason' => 'new',
                'notes' => $data->notes,
                'document_file_id' => null,
                'issue_date' => $issueDate,
                'expiry_date' => $this->expiryDate($template, $issueDate, $data->expiryDate),
                'issued_by' => $actor?->id,
            ]);
            if ($data->lastPrintedAt !== null) {
                $this->printing->recordPast($card, $data->lastPrintedAt, CardPrint::METHOD_IMPORT);
                $card->refresh();
            }

            return $card;
        });
    }

    /**
     * The number the next card would get, without reserving it.
     *
     * @return array{number: string, kept: bool}
     */
    public function nextNumber(Cardholder $cardholder, string $templateId, ?string $familyMemberId): array
    {
        [$template, $member] = $this->resolveTarget($cardholder, $templateId, $familyMemberId);
        $kept = $this->keptNumber($cardholder, $member, $template);

        return ['number' => $kept ?? $this->numbers->preview($template->code), 'kept' => $kept !== null];
    }

    /**
     * Save a new active card with what it prints fixed on it: design, data, photo, signatures and signatory.
     * The holder's current card (locked by the caller) becomes "replaced".
     *
     * @param  array{number: string, rf_card_no: ?string, reason: string, notes: ?string, document_file_id: ?int, issue_date: CarbonImmutable, expiry_date: ?CarbonImmutable, issued_by: ?int}  $attributes
     */
    private function createCard(Cardholder $cardholder, ?FamilyMember $member, CardTemplate $template, ?Card $current, array $attributes): Card
    {
        [$photo, $signature] = $this->cardData->holderImages($cardholder, $member);
        $signatory = $template->authority_type_id === null ? null : $this->signatories->activeFor($template->authority_type_id, $attributes['issue_date']);

        // Free the keys first: the database allows one active card per holder and per number
        if ($current !== null) {
            $this->cards->update($current, [
                'status' => CardStatus::Replaced,
                'status_changed_at' => now(),
                'active_number_key' => null,
                'active_holder_key' => null,
            ]);
        }

        $card = $this->cards->create([
            ...$attributes,
            'cardholder_id' => $cardholder->id,
            'family_member_id' => $member?->id,
            'card_template_id' => $template->id,
            'template_version' => $template->version,
            'layout' => $this->design->snapshot($template),
            'values' => $this->cardData->fields($template, $cardholder, $member, $attributes['issue_date']),
            'photo_file_id' => $photo?->id,
            'signature_file_id' => $signature?->id,
            'signatory_id' => $signatory?->id,
            'status' => CardStatus::Active,
            'active_number_key' => $attributes['number'],
            'active_holder_key' => Card::holderKey($cardholder->id, $member?->id),
        ]);

        if ($current !== null) {
            $this->cards->update($current, ['replaced_by_id' => $card->id]);
        }
        $this->cards->recordAssets($card, $this->assets($card->layout));

        return $card->load(['template', 'familyMember', 'issuer', 'document']);
    }

    /**
     * The given expiry, else the template's validity from the issue date (none when it has no validity).
     */
    private function expiryDate(CardTemplate $template, CarbonImmutable $issueDate, ?CarbonImmutable $given): ?CarbonImmutable
    {
        return $given ?? ($template->validity_months ? $issueDate->addMonths($template->validity_months)->subDay() : null);
    }

    /**
     * Template and holder must fit: family templates are for family members only, and the reverse.
     *
     * @return array{0: CardTemplate, 1: FamilyMember|null}
     */
    private function resolveTarget(Cardholder $cardholder, string $templateId, ?string $familyMemberId): array
    {
        $template = $this->templates->findByPublicId($templateId);
        if ($template === null || ! $template->is_active) {
            throw ValidationException::withMessages(['template' => __('cards.issue.template_unavailable')]);
        }

        $member = null;
        if ($familyMemberId !== null) {
            $member = $this->familyMembers->findByPublicId($familyMemberId);
            if ($member === null || $member->cardholder_id !== $cardholder->id) {
                throw ValidationException::withMessages(['family_member' => __('cards.issue.family_member_unknown')]);
            }
        }

        $forFamily = $template->audience === TemplateAudience::FamilyMember;
        if ($forFamily && $member === null) {
            throw ValidationException::withMessages(['family_member' => __('cards.issue.family_member_required')]);
        }
        if (! $forFamily && $member !== null) {
            throw ValidationException::withMessages(['template' => __('cards.issue.template_not_for_family')]);
        }

        return [$template, $member];
    }

    private function checkReason(IssueCardData $data): void
    {
        $reason = (array) config("cards.reasons.{$data->reason}");
        if (! empty($reason['note']) && $data->notes === null) {
            throw ValidationException::withMessages(['notes' => __('cards.issue.note_required')]);
        }
        if (empty($reason['document']) && ($data->document !== null || $data->documentFileId !== null)) {
            throw ValidationException::withMessages(['document' => __('cards.issue.document_not_for_reason')]);
        }
    }

    /**
     * Legacy "Add Document": stored with the cardholder's documents (never over another file).
     */
    private function documentId(Cardholder $cardholder, IssueCardData $data, User $actor): ?int
    {
        if ($data->document !== null) {
            return $this->fileService->upload($cardholder, 'document', $data->document, __('cards.issue.document_title', ['reason' => __("cards.reasons.{$data->reason}")]), $actor)->id;
        }
        if ($data->documentFileId === null) {
            return null;
        }
        $file = $this->files->findByPublicId($data->documentFileId);
        if ($file === null || $file->cardholder_id !== $cardholder->id || $file->collection !== 'document') {
            throw ValidationException::withMessages(['document_file' => __('cards.issue.document_unknown')]);
        }

        return $file->id;
    }

    /**
     * Keep the holder's number for the same template code when the organization wants that
     * (legacy behaviour); otherwise, or the first time, a new number from the sequence.
     */
    private function number(Cardholder $cardholder, ?FamilyMember $member, CardTemplate $template, CarbonImmutable $issueDate): string
    {
        return $this->keptNumber($cardholder, $member, $template) ?? $this->numbers->next($template->code, $issueDate);
    }

    /**
     * Library images and fonts the design uses; they must stay while the card exists.
     *
     * @param  array{elements: array<int, array<string, mixed>>}  $layout
     * @return array<string, array<int, string>>
     */
    private function assets(array $layout): array
    {
        $elements = collect($layout['elements']);

        return [
            CardAsset::LIBRARY_IMAGE => $elements->pluck('library_image_id')->filter()->map(fn ($id) => (string) $id)->values()->all(),
            CardAsset::FONT => $elements->pluck('style.font_family')->filter()->map(fn ($family) => (string) $family)->values()->all(),
        ];
    }

    private function keptNumber(Cardholder $cardholder, ?FamilyMember $member, CardTemplate $template): ?string
    {
        if ($cardholder->tenant->cardNumberOnReissue() !== 'keep') {
            return null;
        }

        return $this->cards->latestForHolderWithCode($cardholder->id, $member?->id, $template->code)?->number;
    }
}
