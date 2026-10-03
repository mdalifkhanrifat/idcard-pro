<?php

namespace App\Services\MasterData;

use App\Data\MasterData\MasterDataFilter;
use App\Exceptions\BusinessRuleException;
use App\Models\MasterDataItem;
use App\Models\Tenant;
use App\Repositories\Contracts\MasterDataRepositoryInterface;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Business rules for the tenant's reference lists (config/master_data.php).
 */
class MasterDataService
{
    public function __construct(
        private MasterDataRepositoryInterface $items,
        private TenantContext $tenantContext,
    ) {}

    /**
     * Registered list types with their item counts.
     *
     * @return array<int, array{type: string, parent: string|null, has_code: bool, count: int}>
     */
    public function types(): array
    {
        $counts = $this->items->countsByType();

        return collect(config('master_data.types'))
            ->map(fn (array $definition, string $type) => [
                'type' => $type,
                'parent' => $definition['parent'],
                'has_code' => $definition['has_code'],
                'count' => $counts[$type] ?? 0,
            ])
            ->values()
            ->all();
    }

    /**
     * @return LengthAwarePaginator<int, MasterDataItem>|Collection<int, MasterDataItem>
     */
    public function list(string $type, MasterDataFilter $filter): LengthAwarePaginator|Collection
    {
        return $this->items->list($type, $filter);
    }

    /**
     * @param  array<string, mixed>  $data  validated input; `parent_id` is a public id
     */
    public function create(string $type, array $data): MasterDataItem
    {
        $item = $this->items->create([...$this->resolveParent($data), 'type' => $type]);

        return $item->load('parent');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(string $type, MasterDataItem $item, array $data): MasterDataItem
    {
        $this->ensureType($item, $type);

        return $this->items->update($item, $this->resolveParent($data))->load('parent');
    }

    public function delete(string $type, MasterDataItem $item): void
    {
        $this->ensureType($item, $type);

        if ($this->items->hasChildren($item)) {
            throw new BusinessRuleException(__('master_data.has_children'));
        }

        $this->items->delete($item);
    }

    /**
     * Ensure the tenant has every default item from the registry. Safe to run repeatedly.
     *
     * @return int number of items created
     */
    public function seedDefaults(Tenant $tenant): int
    {
        return $this->tenantContext->run($tenant, function (): int {
            $created = 0;

            foreach (config('master_data.types') as $type => $definition) {
                foreach ($definition['defaults'] ?? [] as $position => $name) {
                    $created += (int) $this->items->createIfMissing($type, $name, $position);
                }
            }

            return $created;
        });
    }

    private function ensureType(MasterDataItem $item, string $type): void
    {
        if ($item->type !== $type) {
            throw new NotFoundHttpException;
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function resolveParent(array $data): array
    {
        if (array_key_exists('parent_id', $data)) {
            $data['parent_id'] = $data['parent_id'] ? $this->items->idForPublicId($data['parent_id']) : null;
        }

        return $data;
    }
}
