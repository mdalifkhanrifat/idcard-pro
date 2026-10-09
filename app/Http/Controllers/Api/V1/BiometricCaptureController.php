<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Biometrics\MarkBiometricUnavailableRequest;
use App\Http\Requests\Biometrics\StoreBiometricCaptureRequest;
use App\Http\Resources\BiometricCaptureResource;
use App\Models\BiometricCapture;
use App\Models\Cardholder;
use App\Models\FamilyMember;
use App\Services\Biometrics\BiometricService;
use App\Services\Cardholders\CardholderFileService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * Biometric enrollment (Phase 4): current captures of a cardholder or one of their family members
 * (`family_member`), with whether they meet the organization's settings (`meta.status`).
 */
class BiometricCaptureController extends Controller
{
    public function __construct(
        private BiometricService $biometrics,
        private CardholderFileService $files,
    ) {}

    public function index(Request $request, Cardholder $cardholder): JsonResponse
    {
        Gate::authorize('biometrics.view');

        $input = $request->validate(['family_member' => ['sometimes', 'nullable', 'string', 'max:26']]);
        $overview = $this->biometrics->overview($cardholder, $this->files->familyMemberOf($cardholder, $input['family_member'] ?? null));

        return BiometricCaptureResource::collection($overview['captures'])->additional(['meta' => ['status' => $overview['status']]])->response();
    }

    public function store(StoreBiometricCaptureRequest $request, Cardholder $cardholder): JsonResponse
    {
        $member = $this->files->familyMemberOf($cardholder, $request->validated('family_member'));
        $capture = $this->biometrics->capture($cardholder, $member, $request->toData(), $request->user());

        return $this->created($capture, $cardholder, $member);
    }

    /**
     * Mark a position as not capturable (e.g. a missing finger), with the reason.
     */
    public function unavailable(MarkBiometricUnavailableRequest $request, Cardholder $cardholder): JsonResponse
    {
        $member = $this->files->familyMemberOf($cardholder, $request->validated('family_member'));
        $capture = $this->biometrics->markUnavailable(
            $cardholder,
            $member,
            $request->validated('modality'),
            $request->validated('position'),
            $request->validated('reason'),
            $request->validated('note'),
            $request->user(),
        );

        return $this->created($capture, $cardholder, $member);
    }

    public function destroy(Cardholder $cardholder, BiometricCapture $biometricCapture): JsonResponse
    {
        Gate::authorize('biometrics.delete');

        $this->biometrics->delete($biometricCapture);

        return response()->json(status: 204);
    }

    /**
     * The decrypted image behind a short-lived signed link (usable in <img src>).
     */
    public function image(string $capture): Response
    {
        return $this->biometrics->imageResponse($capture);
    }

    private function created(BiometricCapture $capture, Cardholder $cardholder, ?FamilyMember $member): JsonResponse
    {
        return (new BiometricCaptureResource($capture))
            ->additional(['meta' => ['status' => $this->biometrics->status($cardholder, $member)]])
            ->response()
            ->setStatusCode(201);
    }
}
