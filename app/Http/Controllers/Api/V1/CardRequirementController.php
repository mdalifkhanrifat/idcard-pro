<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Cardholder;
use App\Services\Cardholders\CardholderFileService;
use App\Services\Cards\CardRequirements;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Whether a card can be issued or printed for a person now, and if not, why: for the issue and print
 * screens before they start, and for connected systems (e.g. an HR system deciding what to ask for).
 */
class CardRequirementController extends Controller
{
    public function __construct(
        private CardRequirements $requirements,
        private CardholderFileService $files,
    ) {}

    public function show(Request $request, Cardholder $cardholder): JsonResponse
    {
        Gate::authorize('cards.view');

        $input = $request->validate([
            'moment' => ['required', Rule::in(CardRequirements::MOMENTS)],
            'family_member' => ['sometimes', 'nullable', 'string', 'max:26'],
        ]);
        $member = $this->files->familyMemberOf($cardholder, $input['family_member'] ?? null);
        $problems = $this->requirements->problems($cardholder, $member, $input['moment']);

        return response()->json(['data' => [
            'moment' => $input['moment'],
            'ready' => $problems === [],
            'problems' => $problems,
            'can_override' => $this->requirements->canOverride($request->user()),
        ]]);
    }
}
