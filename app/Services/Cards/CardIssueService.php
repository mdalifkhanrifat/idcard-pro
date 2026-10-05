<?php

namespace App\Services\Cards;

use App\Data\Cards\IssueCardData;
use App\Enums\CardStatus;
use App\Enums\TemplateAudience;
use App\Models\Card;
use App\Models\Cardholder;
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
        $expiryDate = $data->expiryDate ?? ($template->validity_months ? $issueDate->addMonths($template->validity_months)->subDay() : null);
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
            $number = $this->number($cardholder, $member, $template, $issueDate);
            $cardholder->loadMissing(['photo', 'signature']);
            $signatory = $template->authority_type_id === null ? null : $this->signatories->activeFor($template->authority_type_id, $issueDate);

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
                'cardholder_id' => $cardholder->id,
                'family_member_id' => $member?->id,
                'card_template_id' => $template->id,
                'template_version' => $template->version,
                'layout' => $this->design->snapshot($template),
                'values' => $this->cardData->fields($template, $cardholder, $member, $issueDate),
                'number' => $number,
                'rf_card_no' => $data->rfCardNo,
                'reason' => $data->reason,
                'notes' => $data->notes,
                'document_file_id' => $documentId,
                'photo_file_id' => $cardholder->photo?->id,
                'signature_file_id' => $cardholder->signature?->id,
                'signatory_id' => $signatory?->id,
                'issue_date' => $issueDate,
                'expiry_date' => $expiryDate,
                'status' => CardStatus::Active,
                'issued_by' => $actor->id,
                'active_number_key' => $number,
                'active_holder_key' => $holderKey,
            ]);

            if ($current !== null) {
                $this->cards->update($current, ['replaced_by_id' => $card->id]);
            }

            return $card->load(['template', 'familyMember', 'issuer', 'document']);
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

    private function keptNumber(Cardholder $cardholder, ?FamilyMember $member, CardTemplate $template): ?string
    {
        if ($cardholder->tenant->cardNumberOnReissue() !== 'keep') {
            return null;
        }

        return $this->cards->latestForHolderWithCode($cardholder->id, $member?->id, $template->code)?->number;
    }
}
