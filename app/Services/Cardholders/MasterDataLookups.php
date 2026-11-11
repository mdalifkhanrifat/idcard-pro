<?php

namespace App\Services\Cardholders;

use App\Models\Cardholder;
use App\Models\MasterDataItem;
use App\Repositories\Contracts\MasterDataRepositoryInterface;
use Illuminate\Support\Str;

/**
 * Finds the master data a cardholder refers to by name, local name or code (any case), the way
 * people and other systems write it: a file import, an HR sync. Children (unit, sub-region) are
 * looked up inside their parent. Unknown items can be created on request. One instance keeps the
 * lists it has read, so a batch reads each list once.
 */
class MasterDataLookups
{
    /**
     * Child master data column => parent column (children are looked up inside their parent).
     */
    public const PARENTS = ['unit_id' => 'department_id', 'sub_region_id' => 'region_id'];

    /**
     * @var array<string, array<string, MasterDataItem>> type => normalized key => item
     */
    private array $cache = [];

    public function __construct(private MasterDataRepositoryInterface $masterData) {}

    /**
     * Resolve names to public ids, parents first.
     *
     * @param  array<string, string>  $values  cardholder column (e.g. "rank_id") => name, local name or code; '' clears it
     * @param  array<string, mixed>  $input  receives column => public id or null; a parent already given by its id is read from it
     * @param  bool  $createMissing  create unknown items (a dry run only reports them)
     * @param  Cardholder|null  $current  the person being updated: a child given without its parent is looked up in theirs
     * @return array{errors: array<string, string>, created: array<int, string>}
     */
    public function resolve(array $values, array &$input, bool $createMissing, bool $dryRun, ?Cardholder $current = null): array
    {
        $errors = [];
        $created = [];
        $columns = array_keys(array_intersect_key($values, Cardholder::LOOKUPS));
        usort($columns, fn (string $a, string $b) => isset(self::PARENTS[$a]) <=> isset(self::PARENTS[$b]));

        foreach ($columns as $column) {
            $value = trim($values[$column]);
            if ($value === '') {
                $input[$column] = null;

                continue;
            }

            $type = Cardholder::LOOKUPS[$column];
            $parentColumn = self::PARENTS[$column] ?? null;
            $parent = null;
            if ($parentColumn !== null) {
                $parentId = match (true) {
                    array_key_exists($parentColumn, $input) => $input[$parentColumn],
                    array_key_exists($parentColumn, $values) => null,
                    // Not given at all: the parent the person already has
                    default => $current?->{Str::camel(Cardholder::LOOKUPS[$parentColumn])}?->public_id,
                };
                $parent = filled($parentId) ? $this->findByPublicId(Cardholder::LOOKUPS[$parentColumn], (string) $parentId) : null;
                if ($parent === null) {
                    // In a dry run the parent may only be scheduled for creation; then so is the child.
                    if (in_array(Cardholder::LOOKUPS[$parentColumn].': '.trim($values[$parentColumn] ?? ''), $created, true)) {
                        $created[] = "{$type}: {$value}";
                    } else {
                        $errors[$column] = __('cardholders.import.parent_required', ['parent' => __("cardholders.fields.{$parentColumn}")]);
                    }

                    continue;
                }
            }

            $item = $this->find($type, $value, $parent);
            if ($item === null && $createMissing) {
                $created[] = "{$type}: {$value}";
                if (! $dryRun) {
                    $item = $this->create($type, $value, $parent);
                }
            }

            if ($item !== null) {
                $input[$column] = $item->public_id;
            } elseif (! $createMissing) {
                $errors[$column] = __('cardholders.import.unknown_lookup', ['value' => $value, 'list' => __("cardholders.fields.{$column}")]);
            }
        }

        return ['errors' => $errors, 'created' => $created];
    }

    private function find(string $type, string $value, ?MasterDataItem $parent): ?MasterDataItem
    {
        return $this->items($type)[$this->key($value, $parent)] ?? null;
    }

    private function findByPublicId(string $type, string $publicId): ?MasterDataItem
    {
        foreach ($this->items($type) as $item) {
            if ($item->public_id === $publicId) {
                return $item;
            }
        }

        return null;
    }

    private function create(string $type, string $name, ?MasterDataItem $parent): MasterDataItem
    {
        $item = $this->masterData->create(['type' => $type, 'name' => Str::limit($name, 255, ''), 'parent_id' => $parent?->id]);
        $this->cache[$type][$this->key($name, $parent)] = $item;

        return $item;
    }

    /**
     * @return array<string, MasterDataItem>
     */
    private function items(string $type): array
    {
        if (! isset($this->cache[$type])) {
            $this->cache[$type] = [];
            foreach ($this->masterData->allOfType($type) as $item) {
                $parent = $item->parent_id ? (object) ['id' => $item->parent_id] : null;
                foreach (array_filter([$item->name, $item->name_local, $item->code]) as $label) {
                    $this->cache[$type][$this->key($label, $parent)] ??= $item;
                }
            }
        }

        return $this->cache[$type];
    }

    private function key(string $value, MasterDataItem|\stdClass|null $parent): string
    {
        return ($parent->id ?? 0).'|'.mb_strtolower(trim($value));
    }

    /**
     * A master data reference as an HR system sends it: {"id": …} as the API gives it, a name, local name or
     * code, or {"code"|"name"|"name_local": …}. Returns ['id' => public id], a name/code to look up ('' clears), or false.
     *
     * @return array{id: string}|string|false
     */
    public function reference(mixed $value): array|string|false
    {
        if ($value === null) {
            return '';
        }
        if (is_string($value) || is_int($value)) {
            return (string) $value;
        }
        if (is_array($value)) {
            if (isset($value['id']) && is_string($value['id'])) {
                return ['id' => $value['id']];
            }
            foreach (['code', 'name', 'name_local'] as $label) {
                if (isset($value[$label]) && (is_string($value[$label]) || is_int($value[$label]))) {
                    return (string) $value[$label];
                }
            }
        }

        return false;
    }
}
