<?php

namespace App\Http\Requests\Cards;

use App\Data\Cards\PrintCardsData;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Logging a printing: of one card (POST /cards/{card}/prints) or of several at once (POST /cards/prints, `cards`).
 */
class PrintCardsRequest extends FormRequest
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
            'method' => ['sometimes', 'string', Rule::in((array) config('cards.print.methods'))],
            'copies' => ['sometimes', 'integer', 'between:1,'.(int) config('cards.print.max_copies')],
            'note' => ['nullable', 'string', 'max:255'],
            'override_reason' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function toData(): PrintCardsData
    {
        return PrintCardsData::fromArray($this->validated());
    }
}
