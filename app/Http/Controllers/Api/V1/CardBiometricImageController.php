<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Biometrics\CardBiometricImageService;
use Symfony\Component\HttpFoundation\Response;

/**
 * Biometric images issued cards keep as their own copy (4.10), behind short-lived signed links made when
 * the card is printed or shown.
 */
class CardBiometricImageController extends Controller
{
    public function __construct(private CardBiometricImageService $images) {}

    /**
     * The decrypted image behind a signed link (usable in <img src>); never cached.
     */
    public function show(string $image): Response
    {
        return $this->images->imageResponse($image);
    }
}
