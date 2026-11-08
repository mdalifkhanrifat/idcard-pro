<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\Idempotency\IdempotencyConflict;
use App\Services\Idempotency\IdempotencyService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * `idempotent`: a request with an Idempotency-Key header is done once; sent again with the same key it gets the
 * first answer back (header Idempotent-Replayed: true). Without the header nothing changes. Signed-in requests only:
 * a key is the account's own.
 */
class EnsureIdempotency
{
    public const HEADER = 'Idempotency-Key';

    public function __construct(private IdempotencyService $idempotency) {}

    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->header(self::HEADER);
        $user = $request->user();
        if ($key === null || ! $user instanceof User) {
            return $next($request);
        }

        if (! $this->idempotency->isValidKey($key)) {
            return response()->json(['message' => __('integrations.idempotency.invalid'), 'errors' => ['idempotency_key' => [__('integrations.idempotency.invalid')]]], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $record = $this->idempotency->start($user, $key, $this->fingerprint($request));
        } catch (IdempotencyConflict $conflict) {
            return response()->json(['message' => $conflict->getMessage(), 'errors' => ['idempotency_key' => [$conflict->getMessage()]]], $conflict->status);
        }

        if ($record->isAnswered()) {
            return $this->idempotency->replay($record);
        }

        try {
            $response = $next($request);
        } catch (Throwable $e) {
            $this->idempotency->release($record);

            throw $e;
        }
        $this->idempotency->finish($record, $response);

        return $response;
    }

    /**
     * What makes two requests the same: method, address (with its query) and body, byte for byte.
     */
    private function fingerprint(Request $request): string
    {
        return hash('sha256', $request->method()."\n".$request->getRequestUri()."\n".$request->getContent());
    }
}
