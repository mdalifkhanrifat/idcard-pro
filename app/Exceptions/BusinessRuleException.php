<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * Thrown by services when an operation breaks a business rule (e.g. deleting a parent that has children).
 * Rendered as HTTP 422 with the (already translated) message.
 */
class BusinessRuleException extends RuntimeException
{
    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage()], 422);
    }
}
