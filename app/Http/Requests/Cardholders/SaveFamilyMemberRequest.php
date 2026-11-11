<?php

namespace App\Http\Requests\Cardholders;

use App\Models\Cardholder;
use App\Models\FamilyMember;
use App\Services\Cardholders\FamilyMemberValidator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A family member from the form; the rules are shared with the HR sync (FamilyMemberValidator).
 */
class SaveFamilyMemberRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('cardholders.update');
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(FamilyMemberValidator $validator): array
    {
        $cardholder = $this->route('cardholder');
        $member = $this->route('family_member');

        return $validator->rules($cardholder instanceof Cardholder ? $cardholder : null, $member instanceof FamilyMember ? $member : null);
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return app(FamilyMemberValidator::class)->attributes();
    }
}
