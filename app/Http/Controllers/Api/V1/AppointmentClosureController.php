<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Appointments\ListAppointmentClosuresRequest;
use App\Http\Requests\Appointments\SaveAppointmentClosureRequest;
use App\Http\Resources\AppointmentClosureResource;
use App\Models\AppointmentClosure;
use App\Services\Appointments\AppointmentClosureService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * Days appointments are not taken: seen by those who see appointments or may change the settings, changed with
 * the settings (settings.update).
 */
class AppointmentClosureController extends Controller
{
    public function __construct(private AppointmentClosureService $closures) {}

    /**
     * Filters: filter[location] (a place's public id: its closures and those of every place), filter[from],
     * filter[to] (overlapping days; yearly ones always), filter[past]=1 (without a range: those over too —
     * otherwise from the organization's today on); per_page.
     */
    public function index(ListAppointmentClosuresRequest $request): AnonymousResourceCollection
    {
        return AppointmentClosureResource::collection($this->closures->list($request->toFilter()));
    }

    public function store(SaveAppointmentClosureRequest $request): JsonResponse
    {
        return (new AppointmentClosureResource($this->closures->create($request->validated())))->response()->setStatusCode(201);
    }

    public function show(AppointmentClosure $appointmentClosure): AppointmentClosureResource
    {
        abort_unless(Gate::any(['appointments.view', 'settings.update']), 403);

        return new AppointmentClosureResource($appointmentClosure->load('location'));
    }

    public function update(SaveAppointmentClosureRequest $request, AppointmentClosure $appointmentClosure): AppointmentClosureResource
    {
        return new AppointmentClosureResource($this->closures->update($appointmentClosure, $request->validated()));
    }

    public function destroy(AppointmentClosure $appointmentClosure): JsonResponse
    {
        Gate::authorize('settings.update');

        $this->closures->delete($appointmentClosure);

        return response()->json(status: 204);
    }
}
