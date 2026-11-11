<?php

namespace App\Services\Cardholders;

/**
 * Turns one family member an HR system sent (an item of a record's "family") into family member input, as
 * CardholderSyncMapper does for the person. Fields are named as in the family members API; the blood group
 * can be given as the API returns it ({"id": …}, or "blood_group_id") or by name or code. Values are otherwise
 * taken as they are. A field not sent is left as it is; null clears it.
 */
class FamilySyncMapper
{
    /**
     * What the family members API returns but nobody sets: sending a member back as it came is no mistake.
     * ("id" is read by FamilySyncService to find the member, never set.)
     */
    private const READ_ONLY = ['id', 'photo', 'signature', 'biometric_status', 'updated_at'];

    public function __construct(
        private MasterDataLookups $lookups,
        private FamilyMemberValidator $validator,
    ) {}

    /**
     * @param  array<array-key, mixed>  $item
     * @param  bool  $createMissing  create an unknown blood group (a dry run only reports it)
     * @return array{input: array<string, mixed>, errors: array<string, string>, created: array<int, string>}
     */
    public function toInput(array $item, bool $createMissing, bool $dryRun): array
    {
        $fields = $this->validator->rules();
        $input = [];
        $names = [];
        $errors = [];
        foreach ($item as $key => $value) {
            $key = (string) $key;
            if (in_array($key, self::READ_ONLY, true)) {
                continue;
            }

            if ($key === 'blood_group') {
                if (array_key_exists('blood_group_id', $item)) {
                    $errors['blood_group_id'] = __('cardholders.sync.both_given', ['list' => 'blood_group', 'column' => 'blood_group_id']);

                    continue;
                }
                $reference = $this->lookups->reference($value);
                if ($reference === false) {
                    $errors['blood_group_id'] = __('cardholders.sync.invalid_lookup', ['list' => 'blood_group']);
                } elseif (is_array($reference)) {
                    $input['blood_group_id'] = $reference['id'];
                } else {
                    $names['blood_group_id'] = $reference;
                }
            } elseif (isset($fields[$key])) {
                // An HR system may well send a number as a number (a phone, an id)
                $input[$key] = is_int($value) || is_float($value) ? (string) $value : $value;
            } else {
                $errors[$key] = __('cardholders.sync.unknown_family_field', ['field' => $key]);
            }
        }

        $lookups = $this->lookups->resolve($names, $input, $createMissing, $dryRun);

        return ['input' => $input, 'errors' => [...$errors, ...$lookups['errors']], 'created' => $lookups['created']];
    }
}
