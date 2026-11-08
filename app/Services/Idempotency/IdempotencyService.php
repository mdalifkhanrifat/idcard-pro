<?php

namespace App\Services\Idempotency;

use App\Models\IdempotencyKey;
use App\Models\User;
use App\Repositories\Contracts\IdempotencyKeyRepositoryInterface;
use App\Support\Tenancy\TenantContext;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Idempotency-Key: a request sent again with the same key gets the first answer back, and its work is done once.
 * The same key with another request, or while the first is still being worked on, is refused. An answer is kept
 * when it says what came of the request (below 500, but not "too many requests" or "still working"): after a server
 * error the request can be tried again.
 */
class IdempotencyService
{
    public function __construct(
        private IdempotencyKeyRepositoryInterface $keys,
        private TenantContext $tenantContext,
    ) {}

    public function isValidKey(string $key): bool
    {
        return preg_match((string) config('idempotency.key_pattern'), $key) === 1;
    }

    /**
     * Start a request with a key: the record to answer from (answered: replay it) or to fill in when the work is done.
     *
     * @throws IdempotencyConflict the key belongs to another request, or the first is still being worked on
     */
    public function start(User $user, string $key, string $fingerprint): IdempotencyKey
    {
        $expiresAt = now()->addHours((int) config('idempotency.keep_hours'));
        $claimed = $this->keys->claim(['tenant_id' => $this->tenantContext->id(), 'user_id' => $user->id, 'key' => $key, 'fingerprint' => $fingerprint, 'expires_at' => $expiresAt]);
        if ($claimed !== null) {
            return $claimed;
        }

        $held = $this->keys->findFor($user->id, $key);
        if ($held === null) {
            // Removed between the two reads (its request failed): this one is the first now
            return $this->start($user, $key, $fingerprint);
        }

        $stale = $held->expires_at->isPast()
            || (! $held->isAnswered() && $held->updated_at?->lt(now()->subSeconds((int) config('idempotency.stale_seconds'))));
        if ($stale) {
            if ($this->keys->reclaim($held, $fingerprint, $expiresAt)) {
                return $held;
            }

            throw new IdempotencyConflict(Response::HTTP_CONFLICT, __('integrations.idempotency.in_progress'));
        }

        if (! hash_equals($held->fingerprint, $fingerprint)) {
            throw new IdempotencyConflict(Response::HTTP_UNPROCESSABLE_ENTITY, __('integrations.idempotency.mismatch'));
        }
        if (! $held->isAnswered()) {
            throw new IdempotencyConflict(Response::HTTP_CONFLICT, __('integrations.idempotency.in_progress'));
        }

        return $held;
    }

    /**
     * Keep the answer, or let the key go so the request can be tried again.
     */
    public function finish(IdempotencyKey $record, Response $response): void
    {
        $status = $response->getStatusCode();
        $keep = $status < 500 && ! in_array($status, [Response::HTTP_CONFLICT, Response::HTTP_TOO_MANY_REQUESTS], true)
            // A file or a stream is not kept: it is not an answer to hand back from the database
            && ! $response instanceof StreamedResponse && ! $response instanceof BinaryFileResponse;

        if ($keep) {
            $this->keys->update($record, [
                'status_code' => $status,
                'response_body' => (string) $response->getContent(),
                'content_type' => $response->headers->get('Content-Type'),
            ]);
        } else {
            $this->release($record);
        }
    }

    public function release(IdempotencyKey $record): void
    {
        $this->keys->delete($record);
    }

    /**
     * The first answer again, marked as such.
     */
    public function replay(IdempotencyKey $record): Response
    {
        return new Response((string) $record->response_body, (int) $record->status_code, [
            'Content-Type' => $record->content_type ?? 'application/json',
            'Idempotent-Replayed' => 'true',
        ]);
    }
}
