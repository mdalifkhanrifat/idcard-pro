<?php

namespace App\Providers;

use App\Repositories\Contracts\MasterDataRepositoryInterface;
use App\Repositories\Contracts\RoleRepositoryInterface;
use App\Repositories\Contracts\TenantRepositoryInterface;
use App\Repositories\Contracts\UserRepositoryInterface;
use App\Repositories\Eloquent\MasterDataRepository;
use App\Repositories\Eloquent\RoleRepository;
use App\Repositories\Eloquent\TenantRepository;
use App\Repositories\Eloquent\UserRepository;
use Illuminate\Support\ServiceProvider;

/**
 * Binds repository contracts to their implementations.
 * Swap an implementation here (e.g. for caching or another data source) without touching services.
 */
class RepositoryServiceProvider extends ServiceProvider
{
    /**
     * @var array<class-string, class-string>
     */
    public array $bindings = [
        MasterDataRepositoryInterface::class => MasterDataRepository::class,
        UserRepositoryInterface::class => UserRepository::class,
        RoleRepositoryInterface::class => RoleRepository::class,
        TenantRepositoryInterface::class => TenantRepository::class,
    ];
}
