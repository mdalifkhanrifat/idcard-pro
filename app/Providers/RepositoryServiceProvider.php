<?php

namespace App\Providers;

use App\Repositories\Contracts\MasterDataRepositoryInterface;
use App\Repositories\Eloquent\MasterDataRepository;
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
    ];
}
