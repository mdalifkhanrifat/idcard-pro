<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Cards\CardRenderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Data for the render page the PDF engine opens. Reached only through a signed, short-lived link
 * (CardRenderService::link), so it needs no signed-in user.
 */
class CardRenderController extends Controller
{
    public function show(Request $request, CardRenderService $render): JsonResponse
    {
        $cards = array_values(array_filter(explode(',', (string) $request->query('cards'))));

        return response()->json(['data' => $render->data((string) $request->query('tenant'), $cards, (string) $request->query('sides', 'both'), (string) $request->query('sheet', CardRenderService::SHEET_CARD))]);
    }
}
