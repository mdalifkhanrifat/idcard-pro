<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Card;
use App\Services\Cards\CardVerificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Public card verification: no sign-in, rate limited. What it shows is the organization's choice.
 */
class CardVerificationController extends Controller
{
    /**
     * Whether the card with this code is valid, with the details the organization shows; 404 when the code
     * is unknown or the organization has verification off.
     */
    public function show(Request $request, string $code, CardVerificationService $verification): JsonResponse
    {
        $result = $verification->verify($code, $request->ip());
        abort_if($result === null, 404, __('cards.verification.not_found'));

        return response()->json(['data' => $result])->header('Cache-Control', 'no-store');
    }

    /**
     * A card's public checks (signed in): `data` newest first (status at the time, a short device id,
     * time), `meta.total` checks and `meta.devices` different devices.
     */
    public function history(Card $card, CardVerificationService $verification): JsonResponse
    {
        Gate::authorize('cards.view');

        $history = $verification->history($card);

        return response()->json(['data' => $history['checks'], 'meta' => ['total' => $history['total'], 'devices' => $history['devices']]]);
    }
}
