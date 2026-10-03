<?php

namespace Database\Seeders;

use App\Models\MasterDataItem;
use App\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Seeder;

/**
 * Sample reference lists for the local demo organization.
 */
class DemoMasterDataSeeder extends Seeder
{
    public function run(TenantContext $tenantContext): void
    {
        $tenant = Tenant::query()->where('slug', 'acme')->first();

        if ($tenant === null || MasterDataItem::query()->withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('type', 'department')->exists()) {
            return;
        }

        $tenantContext->run($tenant, function (): void {
            $this->list('department', ['Human Resources' => 'HR', 'Finance' => 'FIN', 'Information Technology' => 'IT', 'Operations' => 'OPS', 'Security' => 'SEC']);
            $this->list('designation', ['Manager' => 'MGR', 'Senior Engineer' => 'SE', 'Engineer' => 'ENG', 'Officer' => 'OFF', 'Assistant' => 'AST']);
            $this->list('rank', ['Grade 1' => 'G1', 'Grade 2' => 'G2', 'Grade 3' => 'G3']);
            $this->list('religion', ['Islam' => null, 'Hinduism' => null, 'Buddhism' => null, 'Christianity' => null]);

            foreach (['Dhaka' => ['DHK', ['Savar', 'Dhamrai', 'Keraniganj']], 'Chattogram' => ['CTG', ['Patiya', 'Hathazari']]] as $region => [$code, $areas]) {
                $parent = MasterDataItem::create(['type' => 'region', 'name' => $region, 'code' => $code]);
                foreach ($areas as $position => $area) {
                    MasterDataItem::create(['type' => 'sub_region', 'name' => $area, 'parent_id' => $parent->id, 'sort_order' => $position]);
                }
            }
        });
    }

    /**
     * @param  array<string, string|null>  $items  name => code
     */
    private function list(string $type, array $items): void
    {
        $position = 0;
        foreach ($items as $name => $code) {
            MasterDataItem::create(['type' => $type, 'name' => $name, 'code' => $code, 'sort_order' => $position++]);
        }
    }
}
