<?php

namespace Database\Seeders;

use App\Enums\CardholderStatus;
use App\Enums\EmploymentType;
use App\Models\Cardholder;
use App\Models\MasterDataItem;
use App\Services\Tenants\TenantService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;

/**
 * Fictional cardholders (and units) for the local demo organization.
 */
class DemoCardholderSeeder extends Seeder
{
    public function run(TenantContext $tenantContext, TenantService $tenants): void
    {
        $tenant = $tenants->findBySlug('acme');

        if ($tenant === null || Cardholder::query()->withoutGlobalScopes()->where('tenant_id', $tenant->id)->exists()) {
            return;
        }

        $tenantContext->run($tenant, function () use ($tenant): void {
            $departments = MasterDataItem::query()->where('type', 'department')->get();
            foreach ($departments as $department) {
                foreach (['North Wing', 'South Wing'] as $position => $wing) {
                    MasterDataItem::firstOrCreate(
                        ['type' => 'unit', 'parent_id' => $department->id, 'name' => "{$department->code} {$wing}"],
                        ['sort_order' => $position],
                    );
                }
            }

            $lookup = fn (string $type): Collection => MasterDataItem::query()->where('type', $type)->get();
            [$units, $designations, $ranks, $bloodGroups, $religions] = [$lookup('unit'), $lookup('designation'), $lookup('rank'), $lookup('blood_group'), $lookup('religion')];

            for ($i = 1; $i <= 40; $i++) {
                $unit = $units->random();
                $type = $i % 10 === 0 ? EmploymentType::Contractor : ($i % 13 === 0 ? EmploymentType::Visitor : EmploymentType::Staff);

                Cardholder::factory()->create([
                    'tenant_id' => $tenant->id,
                    'employee_no' => sprintf('ACME-%04d', $i),
                    'employment_type' => $type,
                    'company_name' => $type === EmploymentType::Staff ? null : fake()->company(),
                    'status' => $i % 9 === 0 ? CardholderStatus::Inactive : ($i % 11 === 0 ? CardholderStatus::Retired : CardholderStatus::Active),
                    'email' => fake()->unique()->safeEmail(),
                    'nationality' => 'Bangladeshi',
                    'department_id' => $unit->parent_id,
                    'unit_id' => $unit->id,
                    'designation_id' => $designations->random()->id,
                    'rank_id' => $ranks->random()->id,
                    'blood_group_id' => $bloodGroups->random()->id,
                    'religion_id' => $religions->random()->id,
                ]);
            }
        });
    }
}
