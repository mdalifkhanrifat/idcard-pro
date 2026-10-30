<?php

namespace App\Services\Integrations;

use App\Models\User;
use App\Support\Audit\RecordedChanges;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\NewAccessToken;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * API tokens, of a person or an integration account. Who may do what stays with the owner's role: a token is only a
 * way in, so its abilities are never narrowed here. Every token made or revoked is in the audit log (log
 * "api_tokens", by name and last day, never the token itself).
 */
class ApiTokenService
{
    /**
     * @return Collection<int, PersonalAccessToken>
     */
    public function list(User $owner): Collection
    {
        return $owner->tokens()->latest()->get();
    }

    public function issue(User $owner, string $name, ?CarbonInterface $expiresAt): NewAccessToken
    {
        return DB::transaction(function () use ($owner, $name, $expiresAt): NewAccessToken {
            $token = $owner->createToken($name, ['*'], $expiresAt);
            RecordedChanges::log('api_tokens', $owner, 'created', [], $this->described($owner, $token->accessToken));

            return $token;
        });
    }

    /**
     * Idempotent: an id the owner does not hold changes nothing.
     */
    public function revoke(User $owner, int $tokenId): void
    {
        $token = $owner->tokens()->whereKey($tokenId)->first();

        if ($token instanceof PersonalAccessToken) {
            $this->remove($owner, $token);
        }
    }

    public function revokeAll(User $owner): void
    {
        $owner->tokens()->each(fn (PersonalAccessToken $token) => $this->remove($owner, $token));
    }

    private function remove(User $owner, PersonalAccessToken $token): void
    {
        DB::transaction(function () use ($owner, $token): void {
            $token->delete();
            RecordedChanges::log('api_tokens', $owner, 'deleted', $this->described($owner, $token), []);
        });
    }

    /**
     * @return array{owner: string, name: string, expires_at: string|null}
     */
    private function described(User $owner, PersonalAccessToken $token): array
    {
        return ['owner' => $owner->name, 'name' => $token->name, 'expires_at' => $token->expires_at?->toJSON()];
    }
}
