<?php

namespace App\Support\Tenancy;

use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Context;

/**
 * Holds the tenant that the current request (or job) is operating on.
 */
class TenantContext
{
    private ?Tenant $tenant = null;

    public function set(?Tenant $tenant): void
    {
        $this->tenant = $tenant;

        setPermissionsTeamId($tenant?->id);
        Context::add('tenant_id', $tenant?->id);
    }

    public function get(): ?Tenant
    {
        return $this->tenant;
    }

    public function id(): ?int
    {
        return $this->tenant?->id;
    }

    public function has(): bool
    {
        return $this->tenant !== null;
    }

    /**
     * Start of a day (Y-m-d) in the organization's time zone, as stored (UTC). A date range "from A to B"
     * covers [dayStart(A), dayStart(B) + 1 day), so the last day counts in full.
     */
    public function dayStart(string $date): CarbonImmutable
    {
        $timezone = $this->tenant?->timezone ?: (string) config('app.timezone');

        return CarbonImmutable::parse($date, $timezone)->startOfDay()->utc();
    }

    /**
     * Run the callback in the scope of the given tenant and restore the previous one afterwards.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    public function run(?Tenant $tenant, callable $callback): mixed
    {
        $previous = $this->tenant;
        $this->set($tenant);

        try {
            return $callback();
        } finally {
            $this->set($previous);
        }
    }
}
