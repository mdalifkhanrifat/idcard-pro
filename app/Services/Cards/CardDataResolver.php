<?php

namespace App\Services\Cards;

use App\Models\Cardholder;
use App\Models\CardTemplate;
use App\Models\FamilyMember;
use App\Models\MasterDataItem;
use App\Repositories\Contracts\SignatoryRepositoryInterface;
use App\Services\Cardholders\CardholderFileService;
use App\Services\Signatories\SignatoryService;
use BackedEnum;
use Carbon\CarbonInterface;

/**
 * Values printed on a card, keyed like the designer's data fields (config/card_template_elements.php).
 * The designer preview and printing (3.6) both use this, so a preview always matches the printed card.
 * Text is produced in the organization's language; empty values are left out.
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
    ) {}

    /**
     * @return array{fields: array<string, string>, images: array{photo: string|null, cardholder_signature: string|null, signatory_signature: string|null}}
     */
    public function resolve(CardTemplate $template, Cardholder $cardholder, ?FamilyMember $member = null, ?CarbonInterface $on = null): array
    {
        $locale = $cardholder->tenant->locale ?: app()->getLocale();
        $cardholder->loadMissing([...Cardholder::lookupRelations(), 'photo', 'signature', 'tenant']);
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

        return [
            'fields' => array_filter($fields, fn (string $value) => $value !== ''),
            'images' => [
                // Family members have no photo or signature of their own yet.
                'photo' => $member === null && $cardholder->photo ? $this->files->temporaryUrl($cardholder->photo) : null,
                'cardholder_signature' => $member === null && $cardholder->signature ? $this->files->temporaryUrl($cardholder->signature) : null,
                'signatory_signature' => $signatory ? $this->signatoryService->signatureUrl($signatory) : null,
            ],
        ];
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
