<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Appointments\AddHolidayPresetsRequest;
use App\Http\Resources\AppointmentClosureResource;
use App\Services\Appointments\HolidayPresets;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * The public holidays on a fixed date of the organization's country, offered to add at once as yearly closures
 * of every place: seen like the closures, added with the settings (settings.update).
 */
class AppointmentHolidayPresetController extends Controller
{
    public function __construct(
        private HolidayPresets $presets,
        private TenantContext $tenant,
    ) {}

    public function index(): JsonResponse
    {
        abort_unless(Gate::any(['appointments.view', 'settings.update']), 403);

        return response()->json(['data' => $this->presets->for($this->tenant->get())]);
    }

    /**
     * Adds the chosen ones (those already there are left out): the closures made, and how many were skipped.
     */
    public function store(AddHolidayPresetsRequest $request): JsonResponse
    {
        $result = $this->presets->add($this->tenant->get(), (array) $request->validated('keys'));

        return AppointmentClosureResource::collection(collect($result['added'])->each->load('location'))
            ->additional(['meta' => ['added' => count($result['added']), 'skipped' => $result['skipped']]])
            ->response()
            ->setStatusCode(201);
    }
}
