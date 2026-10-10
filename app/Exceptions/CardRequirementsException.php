<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;

/**
 * A card cannot be issued or printed yet: what is missing, per card or person, and whether the user may
 * go ahead anyway with a reason (override_reason). HTTP 422, like other business rules.
 */
class CardRequirementsException extends BusinessRuleException
{
    /**
     * @param  array<int, array{code: string, message: string, details: array<string, mixed>, card?: string|null}>  $problems
     */
    public function __construct(string $message, public readonly array $problems, public readonly bool $canOverride)
    {
        parent::__construct($message);
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'requirements' => $this->problems,
            'can_override' => $this->canOverride,
        ], 422);
    }
}
