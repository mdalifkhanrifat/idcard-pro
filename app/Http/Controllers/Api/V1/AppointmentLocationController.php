<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Appointments\SaveAppointmentLocationRequest;
use App\Http\Resources\AppointmentLocationResource;
use App\Models\AppointmentLocation;
use App\Services\Appointments\AppointmentLocationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * Places people come to for an appointment: seen by those who see appointments or may change the settings,
 * changed with the settings (settings.update).
 */
class AppointmentLocationController extends Controller
{
    public function __construct(private AppointmentLocationService $locations) {}

    /**
     * Filters: filter[search] (name in either language, or address), filter[is_active] (1/0).
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        abort_unless(Gate::any(['appointments.view', 'settings.update']), 403);

        $filter = $request->validate([
            'filter' => ['sometimes', 'array:search,is_active'],
            'filter.search' => ['nullable', 'string', 'max:100'],
            'filter.is_active' => ['nullable', 'boolean'],
        ])['filter'] ?? [];

        return AppointmentLocationResource::collection($this->locations->list(
            $filter['search'] ?? null,
            isset($filter['is_active']) ? filter_var($filter['is_active'], FILTER_VALIDATE_BOOL) : null,
        ));
    }

    public function store(SaveAppointmentLocationRequest $request): JsonResponse
    {
        return (new AppointmentLocationResource($this->locations->create($request->validated())))->response()->setStatusCode(201);
    }

    public function show(AppointmentLocation $appointmentLocation): AppointmentLocationResource
    {
        abort_unless(Gate::any(['appointments.view', 'settings.update']), 403);

        return new AppointmentLocationResource($appointmentLocation);
    }

    public function update(SaveAppointmentLocationRequest $request, AppointmentLocation $appointmentLocation): AppointmentLocationResource
    {
        return new AppointmentLocationResource($this->locations->update($appointmentLocation, $request->validated()));
    }

    public function destroy(AppointmentLocation $appointmentLocation): JsonResponse
    {
        Gate::authorize('settings.update');

        $this->locations->delete($appointmentLocation);

        return response()->json(status: 204);
    }
}
