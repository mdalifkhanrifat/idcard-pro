<?php

namespace App\Repositories\Contracts;

use App\Data\CardTemplates\CardTemplateFilter;
use App\Models\CardTemplate;
use App\Models\MasterDataItem;
use Illuminate\Support\Collection;

/**
 * @extends RepositoryInterface<CardTemplate>
 */
interface CardTemplateRepositoryInterface extends RepositoryInterface
{
    /**
     * @return Collection<int, CardTemplate>
     */
    public function list(CardTemplateFilter $filter): Collection;

    public function usesMasterDataItem(MasterDataItem $item): bool;
}
