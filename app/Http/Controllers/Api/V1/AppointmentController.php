<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Appointments\BookAppointmentRequest;
use App\Http\Requests\Appointments\ListAppointmentsRequest;
use App\Http\Resources\AppointmentResource;
use App\Models\Appointment;
use App\Services\Appointments\AppointmentBookingService;
use App\Services\Appointments\AppointmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * Appointments (5.4): seen with appointments.view (also while the module is off — their history), booked by staff
 * with appointments.book, by the same rules as the free times.
 */
class AppointmentController extends Controller
{
    public function __construct(private AppointmentBookingService $booking, private AppointmentService $appointments) {}

    /**
     * Filters: filter[from], filter[to] (days of the organization, both whole; without them from its today on,
     * or every day with filter[past]=1), filter[location], filter[status] (one, several comma-separated or a
     * list), filter[service], filter[source], filter[cardholder] (theirs and their family members'),
     * filter[family_member], filter[search] (number, cardholder's name/local name/employee number, family
     * member's name); sort=date|number|created_at (- reverses; default date, then time); per_page. meta names
     * the statuses, sources and kinds of visit to filter by.
     */
    public function index(ListAppointmentsRequest $request): AnonymousResourceCollection
    {
        return AppointmentResource::collection($this->appointments->list($request->toFilter()))
            ->additional(['meta' => $this->appointments->options()]);
    }

    public function show(Appointment $appointment): AppointmentResource
    {
        Gate::authorize('appointments.view');

        return new AppointmentResource($this->appointments->details($appointment));
    }

    public function store(BookAppointmentRequest $request): JsonResponse
    {
        /** @var array{location: string, service: string, cardholder: string, family_member?: string|null, date: string, time?: string|null, note?: string|null} $data */
        $data = $request->validated();

        return (new AppointmentResource($this->booking->book($data, $request->user())))->response()->setStatusCode(201);
    }
}
