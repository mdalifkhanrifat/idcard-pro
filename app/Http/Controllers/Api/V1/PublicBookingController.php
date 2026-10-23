<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Appointments\PublicBooking\PublicBookingService;
use Illuminate\Http\JsonResponse;

/**
 * The public booking link (5.6): no sign-in; the organization by its slug (public.booking), rate limited per
 * address. A person proves who they are with their employee number and the organization's method, then books,
 * sees, moves and cancels their own appointments by the organization's rules.
 */
class PublicBookingController extends Controller
{
    public function __construct(private PublicBookingService $booking) {}

    /**
     * The organization, the kinds of visit offered on the link and the places that take them, how a person proves
     * who they are, what they may change later and how long they stay signed in.
     */
    public function show(): JsonResponse
    {
        return response()->json(['data' => $this->booking->info()]);
    }
}
