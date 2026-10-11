<?php

namespace App\Services\Cards;

use App\Models\Card;
use App\Models\Cardholder;
use App\Models\CardholderFile;
use App\Models\CardTemplate;
use App\Models\FamilyMember;
use App\Models\MasterDataItem;
use App\Repositories\Contracts\SignatoryRepositoryInterface;
use App\Services\Biometrics\CardBiometricImageService;
use App\Services\Cardholders\CardholderFileService;
use App\Services\Signatories\SignatoryService;
use BackedEnum;
use Carbon\CarbonInterface;

/**
 * Values printed on a card, keyed like the designer's data fields (config/card_template_elements.php).
 * The designer preview and printing (3.6) both use this, so a preview always matches the printed card.
 * Text is produced in the organization's language; empty values are left out. A missing photo or signature
 * is null here: the element's own image (uploaded in the designer) is then printed instead.
 * Family cards use the family member's own photo and signature, never the cardholder's.
 * Biometric images (designer `biometric` elements) are keyed by the element's field; null prints the
 * element's fallback (see CardBiometricImageService for which image a card prints).
 */
class CardDataResolver
{
    public const DATE_FORMAT = 'd/m/Y';

    /**
     * Cardholder columns printed as they are.
     */
    private const TEXT_COLUMNS = [
        'full_name', 'full_name_local', 'nationality', 'national_id', 'passport_no', 'email', 'personal_phone',
        'emergency_phone', 'present_address', 'permanent_address', 'postal_code', 'height', 'weight', 'eye_color',
        'complexion', 'identification_mark', 'employee_no', 'secondary_no', 'retired_id_no', 'posting', 'company_name',
    ];

    private const DATE_COLUMNS = ['date_of_birth', 'joining_date', 'retirement_date'];

    private const FAMILY_TEXT_COLUMNS = ['name', 'name_local', 'phone', 'national_id', 'passport_no', 'identification_mark', 'card_no'];

    public function __construct(
        private SignatoryRepositoryInterface $signatories,
        private SignatoryService $signatoryService,
        private CardholderFileService $files,
        private CardBiometricImageService $biometricImages,
    ) {}

    /**
     * Biometric images are left out with `withBiometrics` false (people who may not see them).
     *
     * @return array{fields: array<string, string>, images: array{photo: string|null, cardholder_signature: string|null, signatory_signature: string|null, biometrics: array<string, string|null>}}
     */
    public function resolve(CardTemplate $template, Cardholder $cardholder, ?FamilyMember $member = null, ?CarbonInterface $on = null, bool $withBiometrics = true): array
    {
        $signatory = $template->authority_type_id === null ? null : $this->signatories->activeFor($template->authority_type_id, $on ?? now());
        [$photo, $signature] = $this->holderImages($cardholder, $member);

        return [
            'fields' => $this->fields($template, $cardholder, $member, $on),
            'images' => [
                'photo' => $photo ? $this->files->temporaryUrl($photo) : null,
                'cardholder_signature' => $signature ? $this->files->temporaryUrl($signature) : null,
                'signatory_signature' => $signatory ? $this->signatoryService->signatureUrl($signatory) : null,
                // What the template's biometric elements would print now
                'biometrics' => $withBiometrics ? $this->biometricImages->latestUrls(
                    CardBiometricImageService::fieldsIn(['elements' => $template->elements()->get(['type', 'field', 'style'])->toArray()]),
                    $cardholder,
                    $member,
                ) : [],
            ],
        ];
    }

    /**
     * The current photo and signature printed for the card holder: the family member's own on a family card
     * (null when they have none, so the cardholder's photo never appears on a relative's card).
     *
     * @return array{0: CardholderFile|null, 1: CardholderFile|null}
     */
    public function holderImages(Cardholder $cardholder, ?FamilyMember $member = null): array
    {
        $holder = $member ?? $cardholder;
        $holder->loadMissing(['photo', 'signature']);

        return [$holder->photo, $holder->signature];
    }

    /**
     * What an issued card prints: its text fixed at issue, its number and dates, and the photo,
     * signatures and signatory it was issued with, and its biometric images (left out with `withBiometrics`
     * false, for uses that never show them, such as the public verification page).
     *
     * @return array{fields: array<string, string>, images: array{photo: string|null, cardholder_signature: string|null, signatory_signature: string|null, biometrics: array<string, string|null>}}
     */
    public function forCard(Card $card, bool $withBiometrics = true): array
    {
        $card->loadMissing(['photo', 'signature', 'signatory', 'tenant', 'cardholder.tenant', 'familyMember']);
        $fields = [
            ...($card->values ?? []),
            'card.number' => $card->number,
            'card.issue_date' => $this->date($card->issue_date),
            'card.expiry_date' => $this->date($card->expiry_date),
            // Empty (left out) while the organization has verification off
            'card.verify_url' => (string) $card->tenant->verifyUrl($card->verify_code),
        ];

        return [
            'fields' => array_filter($fields, fn (string $value) => $value !== ''),
            'images' => [
                'photo' => $card->photo ? $this->files->temporaryUrl($card->photo) : null,
                'cardholder_signature' => $card->signature ? $this->files->temporaryUrl($card->signature) : null,
                'signatory_signature' => $card->signatory ? $this->signatoryService->signatureUrl($card->signatory) : null,
                'biometrics' => $withBiometrics ? $this->biometricImages->urlsForCard($card, $card->cardholder, $card->familyMember) : [],
            ],
        ];
    }

    /**
     * Text values of a cardholder (and family member) as printed; `card.*` fields come from the issued card.
     *
     * @return array<string, string>
     */
    public function fields(CardTemplate $template, Cardholder $cardholder, ?FamilyMember $member = null, ?CarbonInterface $on = null): array
    {
        $locale = $cardholder->tenant->locale ?: app()->getLocale();
        $cardholder->loadMissing([...Cardholder::lookupRelations(), 'tenant']);
        $fields = [];

        foreach (self::TEXT_COLUMNS as $column) {
            $fields[$column] = (string) $cardholder->{$column};
        }
        foreach (self::DATE_COLUMNS as $column) {
            $fields[$column] = $this->date($cardholder->{$column});
        }
        $fields['gender'] = $this->label('gender', $cardholder->gender, $locale);
        $fields['marital_status'] = $this->label('marital_status', $cardholder->marital_status, $locale);
        $fields['employment_type'] = $this->label('employment_type', $cardholder->employment_type, $locale);
        foreach (Cardholder::LOOKUPS as $type) {
            $fields[$type] = $this->lookup($cardholder->{str($type)->camel()->toString()});
        }

        foreach ($cardholder->custom_fields ?? [] as $key => $value) {
            $fields["custom.{$key}"] = $this->customValue($value, $locale);
        }

        if ($member !== null) {
            $member->loadMissing('bloodGroup');
            foreach (self::FAMILY_TEXT_COLUMNS as $column) {
                $fields["family.{$column}"] = (string) $member->{$column};
            }
            $fields['family.date_of_birth'] = $this->date($member->date_of_birth);
            $fields['family.gender'] = $this->label('gender', $member->gender, $locale);
            $fields['family.relation'] = $this->label('relation', $member->relation, $locale);
            $fields['family.blood_group'] = $this->lookup($member->bloodGroup);
        }

        $signatory = $template->authority_type_id === null ? null : $this->signatories->activeFor($template->authority_type_id, $on ?? now());
        $fields['signatory.name'] = (string) $signatory?->name;
        $fields['signatory.designation'] = (string) $signatory?->designation;
        $fields['organization.name'] = $cardholder->tenant->name;

        return array_filter($fields, fn (string $value) => $value !== '');
    }

    private function date(?CarbonInterface $date): string
    {
        return $date?->format(self::DATE_FORMAT) ?? '';
    }

    private function label(string $group, ?BackedEnum $value, string $locale): string
    {
        return $value === null ? '' : (string) __("cards.values.{$group}.{$value->value}", locale: $locale);
    }

    private function lookup(?MasterDataItem $item): string
    {
        return (string) $item?->name;
    }

    private function customValue(mixed $value, string $locale): string
    {
        return match (true) {
            is_bool($value) => (string) __($value ? 'cards.values.yes' : 'cards.values.no', locale: $locale),
            is_array($value) => implode(', ', array_map(strval(...), $value)),
            default => (string) $value,
        };
    }
}
