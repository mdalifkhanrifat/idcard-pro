<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Appointments\BookAppointmentRequest;
use App\Http\Resources\AppointmentResource;
use App\Services\Appointments\AppointmentBookingService;
use Illuminate\Http\JsonResponse;

/**
 * Appointments (5.4): booked by staff with appointments.book, by the same rules as the free times.
 */
class AppointmentController extends Controller
{
    public function __construct(private AppointmentBookingService $booking) {}

    public function store(BookAppointmentRequest $request): JsonResponse
    {
        /** @var array{location: string, service: string, cardholder: string, family_member?: string|null, date: string, time?: string|null, note?: string|null} $data */
        $data = $request->validated();

        return (new AppointmentResource($this->booking->book($data, $request->user())))->response()->setStatusCode(201);
    }
}
