<?php

namespace App\Repositories\Eloquent;

use App\Repositories\Contracts\CardSequenceRepositoryInterface;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

class CardSequenceRepository implements CardSequenceRepositoryInterface
{
    public function __construct(private TenantContext $tenantContext) {}

    public function increment(string $key): int
    {
        $tenantId = $this->tenantContext->id();
        $row = $this->locked($tenantId, $key);

        if ($row === null) {
            try {
                DB::table('card_sequences')->insert(['tenant_id' => $tenantId, 'key' => $key, 'last_value' => 0, 'created_at' => now(), 'updated_at' => now()]);
            } catch (UniqueConstraintViolationException) {
                // Another request created the counter first; lock theirs.
            }
            $row = $this->locked($tenantId, $key);
        }

        $next = (int) $row->last_value + 1;
        DB::table('card_sequences')->where('id', $row->id)->update(['last_value' => $next, 'updated_at' => now()]);

        return $next;
    }

    public function peekNext(string $key): int
    {
        return (int) DB::table('card_sequences')
            ->where('tenant_id', $this->tenantContext->id())
            ->where('key', $key)
            ->value('last_value') + 1;
    }

    private function locked(?int $tenantId, string $key): ?object
    {
        return DB::table('card_sequences')->where('tenant_id', $tenantId)->where('key', $key)->lockForUpdate()->first();
    }
}
