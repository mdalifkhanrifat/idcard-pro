<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Middleware\RequirePublicBookingSession;
use App\Http\Requests\Appointments\PublicBooking\IdentifyRequest;
use App\Http\Requests\Appointments\PublicBooking\PublicAvailabilityRequest;
use App\Http\Requests\Appointments\PublicBooking\PublicBookRequest;
use App\Http\Requests\Appointments\PublicBooking\PublicMoveRequest;
use App\Http\Requests\Appointments\PublicBooking\VerifyRequest;
use App\Http\Resources\AvailabilityResource;
use App\Http\Resources\PublicAppointmentResource;
use App\Models\AppointmentLocation;
use App\Models\Cardholder;
use App\Models\FamilyMember;
use App\Repositories\Contracts\AppointmentLocationRepositoryInterface;
use App\Services\Appointments\PublicBooking\PublicAppointmentService;
use App\Services\Appointments\PublicBooking\PublicBookingService;
use App\Services\Appointments\PublicBooking\PublicIdentityService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

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
        private PublicAppointmentService $appointments,
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
     * The free times of a place for a kind of visit, as the public link offers them (the hours' notice, its kinds).
     */
    public function availability(PublicAvailabilityRequest $request, AppointmentLocationRepositoryInterface $locations): AvailabilityResource
    {
        [$from, $to] = $request->days();
        /** @var AppointmentLocation $place */
        $place = $locations->findByPublicId((string) $request->validated('location'));

        return new AvailabilityResource($this->appointments->availability($this->signedIn($request), $place, (string) $request->validated('service'), $from, $to, $request->validated('except')));
    }

    /**
     * The person's own appointments and their family members', from today on, each with what they may do now.
     */
    public function appointments(Request $request): AnonymousResourceCollection
    {
        return PublicAppointmentResource::collection($this->appointments->mine($this->signedIn($request)));
    }

    /**
     * Book for oneself or a family member (`location`, `service`, `family_member`, `date`, `time`, `note`).
     */
    public function book(PublicBookRequest $request): JsonResponse
    {
        /** @var array{location: string, service: string, family_member?: string|null, date: string, time?: string|null, note?: string|null} $data */
        $data = $request->validated();

        return (new PublicAppointmentResource($this->appointments->book($this->signedIn($request), $data)))->response()->setStatusCode(201);
    }

    /**
     * Cancel one's own, as far as the organization allows (`reason` optional).
     */
    public function cancel(Request $request, string $appointment): PublicAppointmentResource
    {
        $reason = $request->validate(['reason' => ['sometimes', 'nullable', 'string', 'max:500']])['reason'] ?? null;

        return new PublicAppointmentResource($this->appointments->cancel($this->signedIn($request), $appointment, $reason)->load(['location', 'familyMember']));
    }

    /**
     * Move one's own to another place, day or time, as far as the organization allows.
     */
    public function move(PublicMoveRequest $request, string $appointment): PublicAppointmentResource
    {
        /** @var array{location?: string, date: string, time?: string|null} $data */
        $data = $request->validated();

        return new PublicAppointmentResource($this->appointments->move($this->signedIn($request), $appointment, $data));
    }

    private function signedIn(Request $request): Cardholder
    {
        return $request->attributes->get('booking_person');
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
