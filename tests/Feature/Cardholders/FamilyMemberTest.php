<?php

use App\Models\FamilyMember;
use App\Support\Tenancy\TenantContext;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    [$this->tenant, $this->owner] = tenantWithOwner();
    $this->cardholder = cardholder($this->tenant);
    $this->base = "/api/v1/cardholders/{$this->cardholder->public_id}/family-members";
});

it('adds, lists, updates and removes family members', function () {
    Sanctum::actingAs($this->owner);
    $bloodGroup = masterItem($this->tenant, ['type' => 'blood_group', 'name' => 'Z-']);

    $id = $this->postJson($this->base, [
        'relation' => 'spouse',
        'name' => 'Salma Begum',
        'name_local' => 'সালমা বেগম',
        'gender' => 'female',
        'date_of_birth' => '1993-02-11',
        'phone' => '01700000000',
        'blood_group_id' => $bloodGroup->public_id,
        'card_no' => 'FAM-77',
    ])
        ->assertCreated()
        ->assertJsonPath('data.relation', 'spouse')
        ->assertJsonPath('data.blood_group.name', 'Z-')
        ->assertJsonPath('data.sort_order', 1)
        ->json('data.id');
    $this->postJson($this->base, ['relation' => 'son', 'name' => 'Rafi'])->assertCreated()->assertJsonPath('data.sort_order', 2);

    $this->getJson($this->base)->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.name', 'Salma Begum');

    $this->putJson("{$this->base}/{$id}", ['relation' => 'spouse', 'name' => 'Salma Akter', 'blood_group_id' => null])
        ->assertOk()
        ->assertJsonPath('data.name', 'Salma Akter')
        ->assertJsonPath('data.blood_group', null);

    $this->deleteJson("{$this->base}/{$id}")->assertNoContent();
    $this->getJson($this->base)->assertJsonCount(1, 'data');
});

it('validates family members', function () {
    Sanctum::actingAs($this->owner);
    $religion = masterItem($this->tenant, ['type' => 'religion', 'name' => 'X']);

    $this->postJson($this->base, ['relation' => 'cousin', 'date_of_birth' => '2999-01-01', 'blood_group_id' => $religion->public_id])
        ->assertJsonValidationErrors(['relation', 'name', 'date_of_birth', 'blood_group_id']);
});

it('keeps family members inside their cardholder and organization', function () {
    Sanctum::actingAs($this->owner);
    $member = app(TenantContext::class)->run($this->tenant, fn () => FamilyMember::factory()->create(['tenant_id' => $this->tenant->id, 'cardholder_id' => $this->cardholder->id]));
    $other = cardholder($this->tenant);

    $this->putJson("/api/v1/cardholders/{$other->public_id}/family-members/{$member->public_id}", ['relation' => 'son', 'name' => 'x'])->assertNotFound();

    [$otherTenant] = tenantWithOwner('beta', 'b@example.com');
    $foreign = cardholder($otherTenant);
    $this->getJson("/api/v1/cardholders/{$foreign->public_id}/family-members")->assertNotFound();
});

it('enforces permissions on family members', function () {
    Sanctum::actingAs(tenantUser($this->tenant, 'Viewer'));
    $this->getJson($this->base)->assertOk();
    $this->postJson($this->base, ['relation' => 'son', 'name' => 'X'])->assertForbidden();
});

it('removes family members with the cardholder and protects their blood group', function () {
    Sanctum::actingAs($this->owner);
    $bloodGroup = masterItem($this->tenant, ['type' => 'blood_group', 'name' => 'Z+']);
    $this->postJson($this->base, ['relation' => 'mother', 'name' => 'Mother', 'blood_group_id' => $bloodGroup->public_id])->assertCreated();

    $this->deleteJson("/api/v1/master-data/blood_group/{$bloodGroup->public_id}")->assertUnprocessable();

    $this->deleteJson("/api/v1/cardholders/{$this->cardholder->public_id}")->assertNoContent();
    expect(FamilyMember::count())->toBe(0);
});
