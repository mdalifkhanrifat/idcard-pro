<?php

namespace App\Http\Requests\Cards;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Shape of a card issue. Template/holder fit, notes per reason and RF number use are checked by CardIssueService.
 */
class IssueCardRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('cards.issue');
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'template' => ['required', 'string', 'size:26'],
            'family_member' => ['nullable', 'string', 'size:26'],
            'reason' => ['required', Rule::in(array_keys((array) config('cards.reasons')))],
            'notes' => ['nullable', 'string', 'max:1000'],
            'issue_date' => ['nullable', 'date_format:Y-m-d'],
            'expiry_date' => ['nullable', 'date_format:Y-m-d'],
            'rf_card_no' => ['nullable', 'string', 'max:60'],
            // Legacy "Add Document" for promotion and lost cards: an upload, or one of the cardholder's documents
            'document' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:10240'],
            'document_file' => ['nullable', 'string', 'size:26'],
        ];
    }
}
