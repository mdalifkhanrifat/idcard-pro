<?php

namespace App\Repositories\Eloquent;

use App\Repositories\Contracts\PasswordResetTokenRepositoryInterface;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

class PasswordResetTokenRepository implements PasswordResetTokenRepositoryInterface
{
    private const TABLE = 'password_reset_tokens';

    public function replace(int $userId, string $tokenHash, CarbonInterface $expiresAt): void
    {
        DB::transaction(function () use ($userId, $tokenHash, $expiresAt): void {
            $this->deleteForUser($userId);
            DB::table(self::TABLE)->insert([
                'user_id' => $userId,
                'token_hash' => $tokenHash,
                'expires_at' => $expiresAt,
                'created_at' => now(),
            ]);
        });
    }

    public function findUserIdByValidHash(string $tokenHash): ?int
    {
        $userId = DB::table(self::TABLE)
            ->where('token_hash', $tokenHash)
            ->where('expires_at', '>', now())
            ->value('user_id');

        return $userId === null ? null : (int) $userId;
    }

    public function deleteForUser(int $userId): void
    {
        DB::table(self::TABLE)->where('user_id', $userId)->delete();
    }
}
