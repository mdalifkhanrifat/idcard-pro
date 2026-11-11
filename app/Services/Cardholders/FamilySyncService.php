<?php

namespace App\Services\Cardholders;

use App\Data\Cardholders\FamilySyncCheck;
use App\Models\Cardholder;
use App\Models\FamilyMember;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use LogicException;

/**
 * Brings a person's family in from an HR system, as part of their record (the "family" of a sync record).
 * Each member is found by our id ("id", as the API gives it), else by the HR system's own id ("external_id"),
 * else by relation and name; a member found by relation and name who has no external_id yet takes the one sent,
 * so members recorded by hand are linked on the first sync instead of being made twice. A member not found is
 * created. Members the list leaves out are kept. Changes go through FamilyMemberService (the audit log and the
 * cardholder.family_changed webhook follow), inside the record's transaction.
 */
class FamilySyncService
{
    public function __construct(
        private FamilySyncMapper $mapper,
        private FamilyMemberValidator $validator,
        private FamilyMemberService $members,
    ) {}

    /**
     * Check a record's family without writing anything.
     *
     * @param  mixed  $family  as sent: a list of members
     * @param  Cardholder|null  $cardholder  the person the record updates; null: a new person, every member is new
     * @param  bool  $createMissing  create an unknown blood group (a dry run only reports it)
     */
    public function check(mixed $family, ?Cardholder $cardholder, bool $createMissing, bool $dryRun): FamilySyncCheck
    {
        if (! is_array($family) || ! array_is_list($family)) {
            return $this->failed('family', __('cardholders.sync.family_not_list'));
        }
        $most = (int) config('cardholder_sync.max_family');
        if (count($family) > $most) {
            return $this->failed('family', __('cardholders.sync.family_too_many', ['max' => $most]));
        }

        // Each member knows their cardholder already: saving one tells webhooks about them without another query
        $existing = $cardholder instanceof Cardholder
            ? $this->members->list($cardholder)->each(fn (FamilyMember $member) => $member->setRelation('cardholder', $cardholder))
            : new Collection;
        $attributes = $this->validator->attributes();
        $planned = [];
        $messages = [];
        $errors = [];
        $lookups = [];
        $claimed = [];
        $externalIds = [];

        foreach ($family as $index => $item) {
            $at = "family.{$index}";
            if (! is_array($item) || ($item !== [] && array_is_list($item))) {
                $messages[] = $errors[$at][] = __('cardholders.sync.family_member_invalid');

                continue;
            }

            $mapped = $this->mapper->toInput($item, $createMissing, $dryRun);
            $own = $mapped['errors'];
            $lookups = [...$lookups, ...$mapped['created']];

            $externalId = $this->externalId($mapped['input']);
            if ($externalId !== null) {
                $key = mb_strtolower($externalId);
                if (isset($externalIds[$key])) {
                    $own['external_id'] ??= __('cardholders.sync.family_external_twice', ['value' => $externalId]);
                }
                $externalIds[$key] = true;
            }

            $found = $this->find($item, $mapped['input'], $externalId, $existing);
            if (is_string($found)) {
                $own[array_key_exists('id', $item) ? 'id' : 'name'] ??= $found;
                $member = null;
            } else {
                $member = $found;
                if ($member !== null && isset($claimed[$member->id])) {
                    $own['id'] ??= __('cardholders.sync.family_member_twice', ['other' => $claimed[$member->id]]);
                } elseif ($member !== null) {
                    $claimed[$member->id] = $index;
                }
            }

            $rules = $this->validator->rules($cardholder, $member);
            // An existing member, or one that cannot be told (its reason is enough): only what is sent is checked
            if ($member !== null || is_string($found)) {
                $rules = array_map(fn (array $fieldRules): array => ['sometimes', ...$fieldRules], $rules);
            }
            $validation = Validator::make($mapped['input'], $rules, [], $attributes);

            foreach ($own as $field => $message) {
                $messages[] = $errors["{$at}.{$field}"][] = $message;
            }
            foreach ($validation->errors()->messages() as $field => $fieldMessages) {
                if (isset($own[$field])) {
                    continue;
                }
                foreach ($fieldMessages as $message) {
                    $messages[] = $errors["{$at}.{$field}"][] = $message;
                }
            }

            if ($own === [] && ! $validation->fails()) {
                $planned[] = ['index' => $index, 'member' => $member, 'input' => $validation->validated()];
            }
        }

        return new FamilySyncCheck($messages === [] ? $planned : [], $messages, $errors, array_values(array_unique($lookups)));
    }

    /**
     * What each member would become, before saving (a dry run): a member found is told as updated, as nothing
     * is compared until it is saved.
     *
     * @return array<int, array{index: int, id: string|null, external_id: string|null, result: string}>
     */
    public function planned(FamilySyncCheck $check): array
    {
        return array_map(fn (array $plan): array => [
            'index' => $plan['index'],
            'id' => $plan['member']?->public_id,
            'external_id' => $this->externalId($plan['input']) ?? $plan['member']?->external_id,
            'result' => $plan['member'] === null ? 'created' : 'updated',
        ], $check->planned);
    }

    /**
     * Save a family that passed its check, for the person just saved.
     *
     * @return array<int, array{index: int, id: string, external_id: string|null, result: 'created'|'updated'|'unchanged'}>
     */
    public function save(FamilySyncCheck $check, Cardholder $cardholder): array
    {
        if (! $check->passes()) {
            throw new LogicException('A family that failed its check cannot be saved.');
        }

        $results = [];
        foreach ($check->planned as $plan) {
            if ($plan['member'] === null) {
                $saved = $this->members->create($cardholder, $plan['input']);
                $result = 'created';
            } else {
                $saved = $this->members->update($plan['member'], $plan['input']);
                $result = $saved->wasChanged() ? 'updated' : 'unchanged';
            }
            $results[] = ['index' => $plan['index'], 'id' => $saved->public_id, 'external_id' => $saved->external_id, 'result' => $result];
        }

        return $results;
    }

    /**
     * The member an item is about: by our id, by the HR system's id, else by relation and name; null: a new member;
     * a string: why it cannot be told.
     *
     * @param  array<array-key, mixed>  $item
     * @param  array<string, mixed>  $input
     * @param  Collection<int, FamilyMember>  $existing
     */
    private function find(array $item, array $input, ?string $externalId, Collection $existing): FamilyMember|string|null
    {
        if (array_key_exists('id', $item)) {
            $id = $item['id'];
            $member = is_string($id) ? $existing->first(fn (FamilyMember $member): bool => $member->public_id === $id) : null;

            return $member ?? __('cardholders.sync.family_id_unknown', ['id' => is_scalar($id) ? (string) $id : '']);
        }

        if ($externalId !== null) {
            $member = $existing->first(fn (FamilyMember $member): bool => $member->external_id !== null && mb_strtolower($member->external_id) === mb_strtolower($externalId));
            if ($member !== null) {
                return $member;
            }
            // Not linked yet: a member recorded by hand takes the HR system's id
            $existing = $existing->filter(fn (FamilyMember $member): bool => $member->external_id === null);
        }

        $relation = $input['relation'] ?? null;
        $name = $input['name'] ?? null;
        if (! is_string($relation) || ! is_string($name) || trim($name) === '') {
            return null;
        }
        $same = $existing->filter(fn (FamilyMember $member): bool => $member->relation->value === $relation
            && mb_strtolower(trim($member->name)) === mb_strtolower(trim($name)))->values();

        if ($same->count() > 1) {
            return __('cardholders.sync.family_ambiguous', ['relation' => __("cards.values.relation.{$relation}"), 'name' => trim($name)]);
        }

        return $same->first();
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function externalId(array $input): ?string
    {
        $externalId = $input['external_id'] ?? null;

        return is_string($externalId) && trim($externalId) !== '' ? trim($externalId) : null;
    }

    private function failed(string $field, string $message): FamilySyncCheck
    {
        return new FamilySyncCheck([], [$message], [$field => [$message]], []);
    }
}
