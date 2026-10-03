<?php

namespace App\Services\Cardholders;

use App\Models\Cardholder;
use App\Models\Qualification;
use App\Repositories\Contracts\QualificationRepositoryInterface;
use Illuminate\Support\Collection;

/**
 * Education records and training courses of a cardholder.
 */
class QualificationService
{
    public function __construct(private QualificationRepositoryInterface $qualifications) {}

    /**
     * @return Collection<int, Qualification>
     */
    public function list(Cardholder $cardholder, ?string $kind = null): Collection
    {
        return $this->qualifications->listFor($cardholder, $kind);
    }

    /**
     * @param  array<string, mixed>  $data  validated input
     */
    public function create(Cardholder $cardholder, array $data): Qualification
    {
        return $this->qualifications->create([
            ...$data,
            'cardholder_id' => $cardholder->id,
            'sort_order' => $data['sort_order'] ?? $this->qualifications->nextSortOrder($cardholder, $data['kind']),
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Qualification $qualification, array $data): Qualification
    {
        return $this->qualifications->update($qualification, $data);
    }

    public function delete(Qualification $qualification): void
    {
        $this->qualifications->delete($qualification);
    }
}
