<?php

use App\Models\Signatory;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    Storage::fake(config('cardholder_files.disk'));
    [$this->tenant, $this->owner] = tenantWithOwner();
});

it('creates, lists, updates and deletes signatories', function () {
    Sanctum::actingAs($this->owner);
    $authority = masterItem($this->tenant, ['type' => 'authority_type', 'name' => 'Head office']);

    $id = $this->postJson('/api/v1/signatories', [
        'name' => 'Abdul Karim', 'name_local' => 'আব্দুল করিম', 'designation' => 'Director', 'authority_type_id' => $authority->public_id,
        'effective_from' => '2026-01-01',
    ])
        ->assertCreated()
        ->assertJsonPath('data.authority_type.name', 'Head office')
        ->assertJsonPath('data.is_active', false)
        ->assertJsonPath('data.signature_url', null)
        ->json('data.id');

    $this->putJson("/api/v1/signatories/{$id}", ['name' => 'Abdul Karim', 'designation' => 'Director General', 'is_active' => true])
        ->assertOk()
        ->assertJsonPath('data.designation', 'Director General')
        ->assertJsonPath('data.is_active', true);

    $this->getJson('/api/v1/signatories?filter[is_active]=1')->assertJsonCount(1, 'data');
    $this->getJson('/api/v1/signatories?filter[search]=general')->assertJsonCount(1, 'data');
    $this->getJson('/api/v1/signatories?filter[is_active]=0')->assertJsonCount(0, 'data');

    $this->deleteJson("/api/v1/signatories/{$id}")->assertNoContent();
    expect(Signatory::count())->toBe(0);
});

it('stores, replaces and removes the signature image', function () {
    Sanctum::actingAs($this->owner);
    $id = $this->postJson('/api/v1/signatories', ['name' => 'A', 'designation' => 'B'])->json('data.id');
    $disk = Storage::disk(config('cardholder_files.disk'));

    $first = $this->post("/api/v1/signatories/{$id}/signature", ['file' => UploadedFile::fake()->image('sign.png', 1600, 400)], ['Accept' => 'application/json'])
        ->assertOk()
        ->json('data.signature_url');
    $firstPath = Signatory::sole()->signature_path;
    $disk->assertExists($firstPath);

    $this->app['auth']->forgetGuards();
    $this->get($first)->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');

    Sanctum::actingAs($this->owner);
    $this->post("/api/v1/signatories/{$id}/signature", ['file' => UploadedFile::fake()->image('new.png')], ['Accept' => 'application/json'])->assertOk();
    $disk->assertMissing($firstPath);
    $this->get($first)->assertNotFound();

    $this->deleteJson("/api/v1/signatories/{$id}/signature")->assertOk()->assertJsonPath('data.signature_url', null);
    expect($disk->allFiles())->toBe([]);
});

it('validates signatories and their signature files', function () {
    Sanctum::actingAs($this->owner);
    $rank = masterItem($this->tenant, ['type' => 'rank', 'name' => 'X']);

    $this->postJson('/api/v1/signatories', ['authority_type_id' => $rank->public_id, 'effective_from' => '2026-05-01', 'effective_to' => '2026-01-01'])
        ->assertJsonValidationErrors(['name', 'designation', 'authority_type_id', 'effective_to']);

    $id = $this->postJson('/api/v1/signatories', ['name' => 'A', 'designation' => 'B'])->json('data.id');
    $this->post("/api/v1/signatories/{$id}/signature", ['file' => UploadedFile::fake()->createWithContent('s.png', '%PDF-1.4')], ['Accept' => 'application/json'])
        ->assertJsonValidationErrors('file');
});

it('protects authority types that signatories use', function () {
    Sanctum::actingAs($this->owner);
    $authority = masterItem($this->tenant, ['type' => 'authority_type', 'name' => 'Head office']);
    $this->postJson('/api/v1/signatories', ['name' => 'A', 'designation' => 'B', 'authority_type_id' => $authority->public_id])->assertCreated();

    $this->deleteJson("/api/v1/master-data/authority_type/{$authority->public_id}")->assertUnprocessable();
});

it('keeps signatories per organization and enforces permissions', function () {
    [$otherTenant] = tenantWithOwner('beta', 'b@example.com');
    $foreign = app(TenantContext::class)->run($otherTenant, fn () => Signatory::factory()->create(['tenant_id' => $otherTenant->id]));

    Sanctum::actingAs($this->owner);
    $this->getJson('/api/v1/signatories')->assertJsonCount(0, 'data');
    $this->getJson("/api/v1/signatories/{$foreign->public_id}")->assertNotFound();

    Sanctum::actingAs(tenantUser($this->tenant, 'Operator'));
    $this->getJson('/api/v1/signatories')->assertForbidden();
    $this->postJson('/api/v1/signatories', ['name' => 'A', 'designation' => 'B'])->assertForbidden();
});
