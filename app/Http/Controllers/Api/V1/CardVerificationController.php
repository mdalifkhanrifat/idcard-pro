<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Cards\CardVerificationService;
use Illuminate\Http\JsonResponse;

/**
 * Public card verification: no sign-in, rate limited. What it shows is the organization's choice.
 */
class CardVerificationController extends Controller
{
    /**
     * Whether the card with this code is valid, with the details the organization shows; 404 when the code
     * is unknown or the organization has verification off.
     */
    public function show(string $code, CardVerificationService $verification): JsonResponse
    {
        $result = $verification->verify($code);
        abort_if($result === null, 404, __('cards.verification.not_found'));

        return response()->json(['data' => $result])->header('Cache-Control', 'no-store');
    }
}
