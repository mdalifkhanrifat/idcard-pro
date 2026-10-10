<?php

namespace App\Http\Resources;

use App\Models\Cardholder;
use App\Models\CardholderFile;
use App\Models\MasterDataItem;
use App\Services\Cardholders\CardholderFileService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

/**
 * @mixin Cardholder
 */
class CardholderResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $lookups = collect(Cardholder::LOOKUPS)
            ->mapWithKeys(fn (string $type) => [$type => $this->whenLoaded(Str::camel($type), fn (?MasterDataItem $item) => $item ? [
                'id' => $item->public_id,
                'name' => $item->name,
                'name_local' => $item->name_local,
            ] : null)])
            ->all();

        return [
            'id' => $this->public_id,
            'full_name' => $this->full_name,
            'full_name_local' => $this->full_name_local,
            'date_of_birth' => $this->date_of_birth?->toDateString(),
            'gender' => $this->gender,
            'marital_status' => $this->marital_status,
            'nationality' => $this->nationality,
            'national_id' => $this->national_id,
            'passport_no' => $this->passport_no,
            'email' => $this->email,
            'personal_phone' => $this->personal_phone,
            'emergency_phone' => $this->emergency_phone,
            'present_address' => $this->present_address,
            'permanent_address' => $this->permanent_address,
            'postal_code' => $this->postal_code,
            'height' => $this->height,
            'weight' => $this->weight,
            'eye_color' => $this->eye_color,
            'complexion' => $this->complexion,
            'identification_mark' => $this->identification_mark,
            'employee_no' => $this->employee_no,
            'secondary_no' => $this->secondary_no,
            'employment_type' => $this->employment_type,
            'joining_date' => $this->joining_date?->toDateString(),
            'retirement_date' => $this->retirement_date?->toDateString(),
            'retired_id_no' => $this->retired_id_no,
            'posting' => $this->posting,
            'previous_office' => $this->previous_office,
            'company_name' => $this->company_name,
            'company_address' => $this->company_address,
            'company_phone' => $this->company_phone,
            ...$lookups,
            'custom_fields' => (object) ($this->custom_fields ?? []),
            'status' => $this->status,
            // Whether the person meets the organization's biometric settings (complete, exceptions, incomplete, not_required)
            'biometric_status' => $this->when((bool) $request->user()?->can('biometrics.view'), fn () => $this->biometric_status),
            'photo' => $this->whenLoaded('photo', fn () => $this->fileSummary($this->photo)),
            // Cards were issued to the person or their family: kept as records, so the cardholder cannot be deleted
            'has_cards' => $this->when($this->resource->getAttribute('cards_exists') !== null, fn () => (bool) $this->resource->getAttribute('cards_exists')),
            // Own active card and whether it was printed (legacy list "Status": PENDING / PRINTED); only for those who may see cards
            'current_card' => $this->when($this->relationLoaded('currentCard') && (bool) $request->user()?->can('cards.view'), fn () => $this->currentCard ? [
                'id' => $this->currentCard->public_id,
                'number' => $this->currentCard->number,
                'print_count' => $this->currentCard->print_count,
                'last_printed_at' => $this->currentCard->last_printed_at,
            ] : null),
            'signature' => $this->whenLoaded('signature', fn () => $this->fileSummary($this->signature)),
            'created_by' => $this->whenLoaded('creator', fn () => $this->creator ? [
                'id' => $this->creator->public_id,
                'name' => $this->creator->name,
            ] : null),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }

    /**
     * @return array{id: string, url: string, width: int|null, height: int|null}|null
     */
    private function fileSummary(?CardholderFile $file): ?array
    {
        return $file ? [
            'id' => $file->public_id,
            'url' => app(CardholderFileService::class)->temporaryUrl($file),
            'width' => $file->width,
            'height' => $file->height,
        ] : null;
    }
}
