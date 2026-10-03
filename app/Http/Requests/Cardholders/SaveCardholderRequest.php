<?php

namespace App\Http\Requests\Cardholders;

use App\Models\Cardholder;
use App\Services\Cardholders\CardholderValidator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Validates creating or updating a cardholder (rules live in CardholderValidator).
 */
class SaveCardholderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can($this->route('cardholder') ? 'cardholders.update' : 'cardholders.create');
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return $this->cardholderRules()->rules($this->cardholder());
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return $this->cardholderRules()->attributes();
    }

    /**
     * @return array<int, \Closure>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $input = $this->all();
            foreach ($this->cardholderRules()->parentErrors($input, $this->cardholder()) as $field => $message) {
                if (! $validator->errors()->hasAny([$field, CardholderValidator::DEPENDENT_LOOKUPS[$field]])) {
                    $validator->errors()->add($field, $message);
                }
            }
        }];
    }

    private function cardholder(): ?Cardholder
    {
        /** @var Cardholder|null $cardholder */
        $cardholder = $this->route('cardholder');

        return $cardholder;
    }

    private function cardholderRules(): CardholderValidator
    {
        return app(CardholderValidator::class);
    }
}
