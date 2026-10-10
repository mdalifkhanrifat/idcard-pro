<?php

namespace App\Data\Cards;

use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;

/**
 * What a card is issued with (legacy card form + "Reason For Print").
 */
final readonly class IssueCardData
{
    public function __construct(
        public string $templateId,
        public string $reason,
        public ?string $familyMemberId = null,
        public ?string $notes = null,
        public ?CarbonImmutable $issueDate = null,
        /** Null: from the template's validity (no expiry when it has none). */
        public ?CarbonImmutable $expiryDate = null,
        public ?string $rfCardNo = null,
        /** An existing document of the cardholder, or a new upload stored as one. */
        public ?string $documentFileId = null,
        public ?UploadedFile $document = null,
        /** Issue although something the organization requires is missing (cards.override_requirements). */
        public ?string $overrideReason = null,
    ) {}

    /**
     * @param  array<string, mixed>  $input  validated request input
     */
    public static function fromArray(array $input, ?UploadedFile $document = null): self
    {
        $date = fn (?string $value) => $value ? CarbonImmutable::parse($value)->startOfDay() : null;
        $text = fn (?string $value) => ($value = trim((string) $value)) === '' ? null : $value;

        return new self(
            templateId: $input['template'],
            reason: $input['reason'],
            familyMemberId: $input['family_member'] ?? null,
            notes: $text($input['notes'] ?? null),
            issueDate: $date($input['issue_date'] ?? null),
            expiryDate: $date($input['expiry_date'] ?? null),
            rfCardNo: $text($input['rf_card_no'] ?? null),
            documentFileId: $input['document_file'] ?? null,
            document: $document,
            overrideReason: $text($input['override_reason'] ?? null),
        );
    }
}
