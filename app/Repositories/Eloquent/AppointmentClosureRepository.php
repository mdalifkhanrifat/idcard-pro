<?php

namespace App\Repositories\Eloquent;

use App\Data\Appointments\AppointmentClosureFilter;
use App\Models\AppointmentClosure;
use App\Models\AppointmentLocation;
use App\Repositories\Contracts\AppointmentClosureRepositoryInterface;
use App\Support\ListPage;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * @extends EloquentRepository<AppointmentClosure>
 */
class AppointmentClosureRepository extends EloquentRepository implements AppointmentClosureRepositoryInterface
{
    protected function model(): string
    {
        return AppointmentClosure::class;
    }

    public function list(AppointmentClosureFilter $filter): LengthAwarePaginator
    {
        return $this->criteria($filter)
            ->with('location')
            ->orderBy('date_from')
            ->orderBy('id')
            ->paginate(ListPage::clamp($filter->perPage))
            ->withQueryString();
    }

    /**
     * @return Builder<AppointmentClosure>
     */
    private function criteria(AppointmentClosureFilter $filter): Builder
    {
        $ranged = $filter->from !== null || $filter->to !== null;

        return $this->query()
            ->when($filter->locationPublicId, fn (Builder $q, string $publicId) => $q->where(fn (Builder $w) => $w
                ->whereNull('location_id')
                ->orWhereIn('location_id', AppointmentLocation::query()->select('id')->where('public_id', $publicId))))
            // Dates are the organization's days, so compared as they are; a yearly closure comes every year
            ->when($ranged, fn (Builder $q) => $q->where(fn (Builder $w) => $w
                ->where('yearly', true)
                ->orWhere(fn (Builder $d) => $d
                    ->when($filter->from, fn (Builder $f, string $from) => $f->where('date_to', '>=', $from))
                    ->when($filter->to, fn (Builder $t, string $to) => $t->where('date_from', '<=', $to)))))
            ->when(! $ranged && ! $filter->past, fn (Builder $q) => $q->where(fn (Builder $w) => $w
                ->where('yearly', true)
                ->orWhere('date_to', '>=', app(TenantContext::class)->today()->toDateString())));
    }
}
