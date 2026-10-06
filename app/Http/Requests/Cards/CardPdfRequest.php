<?php

namespace App\Http\Requests\Cards;

use App\Data\Cards\CardPdfData;
use App\Services\Cards\CardRenderService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * PDF of one card (route {card}) or of a batch (`cards`): sides, and whether to log it as printed.
 */
class CardPdfRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('cards.print');
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $batch = $this->route('card') === null;

        return [
            'cards' => $batch ? ['required', 'array', 'min:1', 'max:'.(int) config('cards.print.max_batch')] : ['prohibited'],
            'cards.*' => ['string', 'size:26'],
            'sides' => ['sometimes', 'string', Rule::in(CardRenderService::SIDES)],
            'record' => ['sometimes', 'boolean'],
            'note' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function toData(): CardPdfData
    {
        return CardPdfData::fromArray($this->validated());
    }
}
