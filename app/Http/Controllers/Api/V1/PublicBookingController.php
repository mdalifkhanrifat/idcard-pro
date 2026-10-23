<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Middleware\RequirePublicBookingSession;
use App\Http\Requests\Appointments\PublicBooking\IdentifyRequest;
use App\Http\Requests\Appointments\PublicBooking\VerifyRequest;
use App\Models\Cardholder;
use App\Models\FamilyMember;
use App\Services\Appointments\PublicBooking\PublicBookingService;
use App\Services\Appointments\PublicBooking\PublicIdentityService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The public booking link (5.6): no sign-in; the organization by its slug (public.booking), rate limited per
 * address. A person proves who they are with their employee number and the organization's method, then books,
 * sees, moves and cancels their own appointments by the organization's rules.
 */
class PublicBookingController extends Controller
{
    public function __construct(
        private PublicBookingService $booking,
        private PublicIdentityService $identity,
    ) {}

    /**
     * The organization, the kinds of visit offered on the link and the places that take them, how a person proves
     * who they are, what they may change later and how long they stay signed in.
     */
    public function show(): JsonResponse
    {
        return response()->json(['data' => $this->booking->info()]);
    }

    /**
     * Start proving who one is (`employee_no`): a challenge for the organization's method — a code goes to the
     * e-mail/phone on record. The same answer whether or not the number is known; at most a few per number an hour.
     */
    public function identify(IdentifyRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->identity->identify((string) $request->validated('employee_no'))]);
    }

    /**
     * Answer the challenge (`challenge` and `code`, `date_of_birth` or `national_id`): a session token
     * (X-Booking-Session) and who it stands for; 422 with the tries left, or to start again.
     */
    public function verify(VerifyRequest $request): JsonResponse
    {
        /** @var array{challenge: string, code?: string|null, date_of_birth?: string|null, national_id?: string|null} $data */
        $data = $request->validated();
        $session = $this->identity->verify($data['challenge'], $data);

        return response()->json(['data' => ['token' => $session['token'], 'expires_in' => $session['expires_in'], 'person' => $this->person($session['person'])]]);
    }

    /**
     * Who the session stands for (and their family members where the organization books for them).
     */
    public function me(Request $request): JsonResponse
    {
        return response()->json(['data' => ['person' => $this->person($request->attributes->get('booking_person')), 'expires_in' => (int) config('appointments.public.session_minutes') * 60]]);
    }

    public function signOut(Request $request): JsonResponse
    {
        $this->identity->signOut((string) $request->header(RequirePublicBookingSession::HEADER));

        return response()->json(null, 204);
    }

    /**
     * A person as the link shows them to themselves: name, employee number and family members — nothing more.
     *
     * @return array{name: string, employee_no: string|null, family_members: array<int, array{id: string, name: string, relation: string}>}
     */
    private function person(Cardholder $person): array
    {
        $families = (bool) app(TenantContext::class)->get()->appointmentSettings()['family_members'];

        return [
            'name' => $person->full_name,
            'employee_no' => $person->employee_no,
            'family_members' => $families ? $person->familyMembers->map(fn (FamilyMember $member) => ['id' => $member->public_id, 'name' => $member->name, 'relation' => $member->relation->value])->values()->all() : [],
        ];
    }
}
