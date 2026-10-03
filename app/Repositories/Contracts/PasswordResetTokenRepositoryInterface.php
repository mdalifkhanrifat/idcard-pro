<?php

namespace App\Repositories\Contracts;

use Carbon\CarbonInterface;

/**
 * Password reset tokens, stored only as SHA-256 hashes and bound to one user.
 */
interface PasswordResetTokenRepositoryInterface
{
    /**
     * Replace any existing token of the user with a new one.
     */
    public function replace(int $userId, string $tokenHash, CarbonInterface $expiresAt): void;

    /**
     * User id of a non-expired token, or null.
     */
    public function findUserIdByValidHash(string $tokenHash): ?int;

    public function deleteForUser(int $userId): void;
}
