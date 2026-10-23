<?php

namespace App\Http\Middleware;

use App\Services\Appointments\PublicBooking\PublicIdentityService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A public booking session (5.6): the person whose token comes in `X-Booking-Session`, renewed by each use, as the
 * request's `booking_person`; 401 when there is none or it has ended.
 */
class RequirePublicBookingSession
{
    public const HEADER = 'X-Booking-Session';

    public function __construct(private PublicIdentityService $identity) {}

    public function handle(Request $request, Closure $next): Response
    {
        $person = $this->identity->person($request->header(self::HEADER));
        abort_if($person === null, Response::HTTP_UNAUTHORIZED, __('appointments.public.signed_out'));
        $request->attributes->set('booking_person', $person);

        return $next($request);
    }
}
