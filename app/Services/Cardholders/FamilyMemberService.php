<?php

namespace App\Services\Cardholders;

use App\Exceptions\BusinessRuleException;
use App\Models\Cardholder;
use App\Models\FamilyMember;
use App\Repositories\Contracts\CardRepositoryInterface;
use App\Repositories\Contracts\FamilyMemberRepositoryInterface;
use App\Repositories\Contracts\MasterDataRepositoryInterface;
use Illuminate\Support\Collection;

/**
 * Family members recorded for a cardholder.
 */
class FamilyMemberService
{
    public function __construct(
        private FamilyMemberRepositoryInterface $members,
        private MasterDataRepositoryInterface $masterData,
        private CardRepositoryInterface $cards,
    ) {}

    /**
     * @return Collection<int, FamilyMember>
     */
    public function list(Cardholder $cardholder): Collection
    {
        return $this->members->listFor($cardholder);
    }

    /**
     * @param  array<string, mixed>  $data  validated input; `blood_group_id` is a public id
     */
    public function create(Cardholder $cardholder, array $data): FamilyMember
    {
        $member = $this->members->create([
            ...$this->resolveBloodGroup($data),
            'cardholder_id' => $cardholder->id,
            'sort_order' => $data['sort_order'] ?? $this->members->nextSortOrder($cardholder),
        ]);

        return $member->load('bloodGroup');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(FamilyMember $member, array $data): FamilyMember
    {
        return $this->members->update($member, $this->resolveBloodGroup($data))->load('bloodGroup');
    }

    public function delete(FamilyMember $member): void
    {
        if ($this->cards->existsForFamilyMember($member->id)) {
            throw new BusinessRuleException(__('cards.in_use.family_member'));
        }
        $this->members->delete($member);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function resolveBloodGroup(array $data): array
    {
        if (array_key_exists('blood_group_id', $data)) {
            $data['blood_group_id'] = $data['blood_group_id'] ? $this->masterData->idForPublicId($data['blood_group_id']) : null;
        }

        return $data;
    }
}
