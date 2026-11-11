<?php

namespace App\Data\Cardholders;

use App\Models\FamilyMember;

/**
 * What a record's "family" would do (FamilySyncService::check()): each member found or new, with its validated
 * input, the members to remove (family_mode "replace"), or the problems by field ("family.0.name"). Nothing is
 * written until save().
 */
final readonly class FamilySyncCheck
{
    /**
     * @param  array<int, array{index: int, member: FamilyMember|null, input: array<string, mixed>}>  $planned  empty when there are messages
     * @param  array<int, string>  $messages  in the reader's language, in the order they were found
     * @param  array<string, array<int, string>>  $errors  the same messages by field, for machines
     * @param  array<int, string>  $lookups  master data that would be (or was) created, as "type: name"
     * @param  array<int, FamilyMember>  $removing  members the list left out, to remove (family_mode "replace" only); empty when there are messages
     */
    public function __construct(
        public array $planned,
        public array $messages,
        public array $errors,
        public array $lookups,
        public array $removing = [],
    ) {}

    public function passes(): bool
    {
        return $this->messages === [];
    }
}
