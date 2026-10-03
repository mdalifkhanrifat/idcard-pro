<?php

use App\Models\MasterDataItem;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

it('lets a platform admin provision an organization with roles, defaults and an owner', function () {
    Sanctum::actingAs(User::factory()->platformAdmin()->create());

    $this->postJson('/api/v1/platform/tenants', [
        'name' => 'Globex', 'slug' => 'GLOBEX', 'timezone' => 'Asia/Dhaka',
        'owner' => ['name' => 'Hank', 'email' => 'Hank@Globex.test', 'password' => 'Strong#Pass789'],
    ])->assertCreated()->assertJsonPath('data.slug', 'globex')->assertJsonPath('data.users_count', 1);

    $owner = User::query()->withoutGlobalScopes()->where('email', 'hank@globex.test')->firstOrFail();
    setPermissionsTeamId($owner->tenant_id);

    expect($owner->hasRole('Owner'))->toBeTrue()
        ->and(MasterDataItem::query()->withoutGlobalScopes()->where('tenant_id', $owner->tenant_id)->where('type', 'blood_group')->count())->toBe(8);
});

it('filters and suspends organizations', function () {
    tenantWithOwner('alpha', 'a@example.com');
    [$beta] = tenantWithOwner('beta', 'b@example.com');
    Sanctum::actingAs(User::factory()->platformAdmin()->create());

    $this->getJson('/api/v1/platform/tenants?filter[slug]=beta')->assertJsonCount(1, 'data');
    $this->putJson("/api/v1/platform/tenants/{$beta->public_id}", ['status' => 'suspended'])->assertJsonPath('data.status', 'suspended');
    $this->getJson('/api/v1/platform/tenants?filter[status]=suspended')->assertJsonCount(1, 'data')->assertJsonPath('data.0.slug', 'beta');
    $this->getJson('/api/v1/platform/tenants?filter[bogus]=1')->assertJsonValidationErrors('filter');
});

it('updates organization settings and stores the logo', function () {
    Storage::fake('public');
    [$tenant, $owner] = tenantWithOwner();
    Sanctum::actingAs($owner);

    $this->post('/api/v1/settings', [
        'name' => 'Acme Global', 'timezone' => 'Asia/Dhaka', 'locale' => 'bn', 'primary_color' => '#112233',
        'logo' => UploadedFile::fake()->image('logo.png', 120, 120),
    ], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('data.name', 'Acme Global')->assertJsonPath('data.primary_color', '#112233');

    Storage::disk('public')->assertExists($tenant->fresh()->logo_path);
});

it('does not let viewers change settings', function () {
    [$tenant] = tenantWithOwner();
    Sanctum::actingAs(tenantUser($tenant, 'Viewer'));

    $this->postJson('/api/v1/settings', ['name' => 'x', 'timezone' => 'UTC', 'locale' => 'en', 'primary_color' => '#000000'])->assertForbidden();
});
