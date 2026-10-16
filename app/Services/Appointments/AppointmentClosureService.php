<?php

namespace App\Services\Appointments;

use App\Data\Appointments\AppointmentClosureFilter;
use App\Models\AppointmentClosure;
use App\Repositories\Contracts\AppointmentClosureRepositoryInterface;
use App\Repositories\Contracts\AppointmentLocationRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Days appointments are not taken: a holiday or a closure of one place or of every place, once or every year.
 * Changes are in the audit log; a place's closures go with it.
 */
class AppointmentClosureService
{
    public function __construct(
        private AppointmentClosureRepositoryInterface $closures,
        private AppointmentLocationRepositoryInterface $locations,
    ) {}

    /**
     * @return LengthAwarePaginator<int, AppointmentClosure>
     */
    public function list(AppointmentClosureFilter $filter): LengthAwarePaginator
    {
        return $this->closures->list($filter);
    }

    /**
     * @param  array<string, mixed>  $data  validated input (`location`: a place's public id, null: every place)
     */
    public function create(array $data): AppointmentClosure
    {
        return $this->closures->create($this->prepare($data))->load('location');
    }

    /**
     * @param  array<string, mixed>  $data  validated input; what is not sent stays
     */
    public function update(AppointmentClosure $closure, array $data): AppointmentClosure
    {
        return $this->closures->update($closure, $this->prepare($data))->load('location');
    }

    public function delete(AppointmentClosure $closure): void
    {
        $this->closures->delete($closure);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function prepare(array $data): array
    {
        if (array_key_exists('location', $data)) {
            $data['location_id'] = $data['location'] === null ? null : $this->locations->findByPublicId((string) $data['location'])?->id;
            unset($data['location']);
        }

        return $data;
    }
}
