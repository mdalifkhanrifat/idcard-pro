<?php

namespace App\Services\Cardholders;

use App\Data\Cardholders\CardholderFilter;
use App\Models\Cardholder;
use App\Models\User;
use App\Repositories\Contracts\CardholderRepositoryInterface;
use App\Repositories\Contracts\MasterDataRepositoryInterface;
use App\Services\CustomFields\CustomFieldService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Manages the cardholders (people who can be issued ID cards) of the current tenant.
 */
class CardholderService
{
    public function __construct(
        private CardholderRepositoryInterface $cardholders,
        private MasterDataRepositoryInterface $masterData,
        private CardholderFileService $files,
        private CustomFieldService $customFields,
    ) {}

    /**
     * @return LengthAwarePaginator<int, Cardholder>
     */
    public function list(CardholderFilter $filter): LengthAwarePaginator
    {
        return $this->cardholders->list($filter);
    }

    public function show(Cardholder $cardholder): Cardholder
    {
        return $this->withDetails($cardholder);
    }

    /**
     * @param  array<string, mixed>  $data  validated input; master data references are public ids
     */
    public function create(array $data, ?User $actor): Cardholder
    {
        $cardholder = $this->cardholders->create($this->withCustomFields($this->resolveLookups($data), null));
        $cardholder->forceFill(['created_by' => $actor?->id])->save();

        return $this->withDetails($cardholder);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Cardholder $cardholder, array $data): Cardholder
    {
        return $this->withDetails($this->cardholders->update($cardholder, $this->withCustomFields($this->resolveLookups($data), $cardholder)));
    }

    /**
     * Delete the cardholder; file records cascade in the database, stored files are removed afterwards.
     */
    public function delete(Cardholder $cardholder): void
    {
        $locations = $this->files->storedLocations($cardholder);

        $this->cardholders->delete($cardholder);
        $this->files->deleteStored($locations);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function withCustomFields(array $data, ?Cardholder $cardholder): array
    {
        if (array_key_exists('custom_fields', $data)) {
            $data['custom_fields'] = $this->customFields->mergeValues('cardholder', $data['custom_fields'] ?? [], $cardholder?->custom_fields);
        }

        return $data;
    }

    private function withDetails(Cardholder $cardholder): Cardholder
    {
        return $cardholder->load([...Cardholder::lookupRelations(), 'creator', 'photo', 'signature']);
    }

    /**
     * Replace master data public ids with internal ids (already validated for tenant and type).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function resolveLookups(array $data): array
    {
        $columns = array_intersect_key(Cardholder::LOOKUPS, $data);
        $ids = $this->masterData->idsForPublicIds(array_values(array_filter(array_intersect_key($data, $columns))));

        foreach (array_keys($columns) as $column) {
            $data[$column] = $data[$column] ? $ids[$data[$column]] ?? null : null;
        }

        return $data;
    }
}
