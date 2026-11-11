<?php

namespace App\Services\Cardholders;

use App\Models\Cardholder;

/**
 * Turns one record an HR system sent (JSON) into cardholder input. Fields are named as in the
 * cardholder API. Master data can be given the way the API returns it ({"id": …}, or "rank_id"
 * with a public id) or by name, local name or code ("rank": "Captain" or {"code": "CPT"}), as a
 * file import gives it. Values are otherwise taken as they are: dates as YYYY-MM-DD, choices by
 * their keys. A field not sent is left as it is; null clears it.
 */
class CardholderSyncMapper
{
    /**
     * What the cardholder API returns but nobody sets: sending a record back as it came is no mistake.
     */
    private const READ_ONLY = [
        'id', 'photo', 'signature', 'created_by', 'created_at', 'updated_at',
        'has_cards', 'has_appointments', 'current_card', 'biometric_status',
    ];

    /**
     * @var array<string, int>|null the fields a record may set, read once
     */
    private ?array $fields = null;

    public function __construct(
        private MasterDataLookups $lookups,
        private CardholderValidator $validator,
    ) {}

    /**
     * @param  array<array-key, mixed>  $record
     * @param  bool  $createMissing  create unknown master data (a dry run only reports it)
     * @param  Cardholder|null  $existing  the person the record updates, if any
     * @return array{input: array<string, mixed>, errors: array<string, string>, created: array<int, string>}
     */
    public function toInput(array $record, bool $createMissing, bool $dryRun, ?Cardholder $existing = null): array
    {
        $fields = $this->fields ??= array_flip(array_filter(array_keys($this->validator->rules()), fn (string $key): bool => ! str_contains($key, '.')));
        $lists = array_flip(Cardholder::LOOKUPS);

        $input = [];
        $names = [];
        $errors = [];
        foreach ($record as $key => $value) {
            $key = (string) $key;
            if (in_array($key, self::READ_ONLY, true)) {
                continue;
            }

            if (isset($lists[$key])) {
                $column = $lists[$key];
                if (array_key_exists($column, $record)) {
                    $errors[$column] = __('cardholders.sync.both_given', ['list' => $key, 'column' => $column]);

                    continue;
                }
                $reference = $this->lookups->reference($value);
                if ($reference === false) {
                    $errors[$column] = __('cardholders.sync.invalid_lookup', ['list' => $key]);
                } elseif (is_array($reference)) {
                    $input[$column] = $reference['id'];
                } else {
                    $names[$column] = $reference;
                }
            } elseif (isset($fields[$key])) {
                // An HR system may well send a number as a number (an employee number, a phone)
                $input[$key] = is_int($value) || is_float($value) ? (string) $value : $value;
            } else {
                $errors[$key] = __('cardholders.sync.unknown_field', ['field' => $key]);
            }
        }

        if (blank($input['employee_no'] ?? null)) {
            $errors['employee_no'] = __('cardholders.sync.employee_no_required');
        }

        $lookups = $this->lookups->resolve($names, $input, $createMissing, $dryRun, $existing);

        return ['input' => $input, 'errors' => [...$errors, ...$lookups['errors']], 'created' => $lookups['created']];
    }
}
