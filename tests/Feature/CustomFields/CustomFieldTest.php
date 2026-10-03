<?php

use App\Models\CustomField;
use App\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    [$this->tenant, $this->owner] = tenantWithOwner();
});

function customField(Tenant $tenant, array $attributes): CustomField
{
    return app(TenantContext::class)->run($tenant, fn () => CustomField::factory()->create([...$attributes, 'tenant_id' => $tenant->id]));
}

it('manages field definitions and keeps key and type fixed', function () {
    Sanctum::actingAs($this->owner);

    $id = $this->postJson('/api/v1/custom-fields', [
        'entity' => 'cardholder', 'key' => 'shift', 'label' => 'Shift', 'label_local' => 'শিফট', 'type' => 'select', 'options' => ['Day', 'Night'],
    ])
        ->assertCreated()
        ->assertJsonPath('data.options', ['Day', 'Night'])
        ->assertJsonPath('data.sort_order', 1)
        ->json('data.id');

    $this->postJson('/api/v1/custom-fields', ['entity' => 'cardholder', 'key' => 'shift', 'label' => 'Again', 'type' => 'text'])->assertJsonValidationErrors('key');
    $this->postJson('/api/v1/custom-fields', ['entity' => 'cardholder', 'key' => 'Bad Key', 'label' => 'X', 'type' => 'select'])->assertJsonValidationErrors(['key', 'options']);
    $this->putJson("/api/v1/custom-fields/{$id}", ['label' => 'Shift', 'type' => 'text'])->assertJsonValidationErrors('type');

    $this->putJson("/api/v1/custom-fields/{$id}", ['label' => 'Work shift', 'options' => ['Day', 'Night', 'Rotating'], 'is_required' => true])
        ->assertOk()
        ->assertJsonPath('data.label', 'Work shift')
        ->assertJsonPath('data.is_required', true)
        ->assertJsonPath('data.options.2', 'Rotating');

    $this->getJson('/api/v1/custom-fields')->assertJsonCount(1, 'data');
    $this->deleteJson("/api/v1/custom-fields/{$id}")->assertNoContent();
    $this->getJson('/api/v1/custom-fields')->assertJsonCount(0, 'data');
});

it('validates and stores values by type', function () {
    Sanctum::actingAs($this->owner);
    customField($this->tenant, ['key' => 'shift', 'label' => 'Shift', 'type' => 'select', 'options' => ['Day', 'Night'], 'is_required' => true]);
    customField($this->tenant, ['key' => 'locker_no', 'label' => 'Locker', 'type' => 'number']);
    customField($this->tenant, ['key' => 'vaccinated', 'label' => 'Vaccinated', 'type' => 'checkbox']);
    customField($this->tenant, ['key' => 'badge_expiry', 'label' => 'Badge expiry', 'type' => 'date']);

    $this->postJson('/api/v1/cardholders', ['full_name' => 'X', 'custom_fields' => ['shift' => 'Evening', 'locker_no' => 'abc', 'badge_expiry' => '31/12/2026', 'unknown' => 'x']])
        ->assertJsonValidationErrors(['custom_fields', 'custom_fields.shift', 'custom_fields.locker_no', 'custom_fields.badge_expiry']);
    $this->postJson('/api/v1/cardholders', ['full_name' => 'X', 'custom_fields' => ['locker_no' => 4]])
        ->assertJsonValidationErrors('custom_fields.shift');

    $id = $this->postJson('/api/v1/cardholders', ['full_name' => 'X', 'custom_fields' => ['shift' => 'Night', 'locker_no' => '42', 'vaccinated' => '1', 'badge_expiry' => '2026-12-31']])
        ->assertCreated()
        ->assertJsonPath('data.custom_fields.shift', 'Night')
        ->assertJsonPath('data.custom_fields.locker_no', 42)
        ->assertJsonPath('data.custom_fields.vaccinated', true)
        ->json('data.id');

    // Clearing a value removes it; the other values stay.
    $this->putJson("/api/v1/cardholders/{$id}", ['full_name' => 'X', 'custom_fields' => ['shift' => 'Day', 'locker_no' => null]])
        ->assertOk()
        ->assertJsonPath('data.custom_fields.shift', 'Day')
        ->assertJsonMissingPath('data.custom_fields.locker_no')
        ->assertJsonPath('data.custom_fields.vaccinated', true);
});

it('keeps values of fields that were deactivated', function () {
    Sanctum::actingAs($this->owner);
    $field = customField($this->tenant, ['key' => 'old_code', 'label' => 'Old code', 'type' => 'text']);
    $id = $this->postJson('/api/v1/cardholders', ['full_name' => 'X', 'custom_fields' => ['old_code' => 'A1']])->json('data.id');

    $field->update(['is_active' => false]);

    $this->putJson("/api/v1/cardholders/{$id}", ['full_name' => 'Y', 'custom_fields' => []])
        ->assertOk()
        ->assertJsonPath('data.custom_fields.old_code', 'A1');
});

it('applies the student preset once', function () {
    Sanctum::actingAs($this->owner);

    $this->getJson('/api/v1/custom-fields/presets')->assertJsonPath('data.0.name', 'student')->assertJsonPath('data.0.fields', 4);
    $this->postJson('/api/v1/custom-fields/presets/student')->assertOk()->assertJsonPath('created', 4);
    $this->postJson('/api/v1/custom-fields/presets/student')->assertOk()->assertJsonPath('created', 0);
    $this->postJson('/api/v1/custom-fields/presets/spaceship')->assertUnprocessable();

    $this->getJson('/api/v1/custom-fields?active=1')
        ->assertJsonCount(4, 'data')
        ->assertJsonPath('data.0.key', 'class')
        ->assertJsonPath('data.0.group', 'Student information');
});

it('filters cardholders by custom field values', function () {
    Sanctum::actingAs($this->owner);
    customField($this->tenant, ['key' => 'class', 'label' => 'Class', 'type' => 'text']);
    customField($this->tenant, ['key' => 'hostel', 'label' => 'Hostel', 'type' => 'checkbox']);
    $this->postJson('/api/v1/cardholders', ['full_name' => 'Ten A', 'custom_fields' => ['class' => 'Ten', 'hostel' => true]]);
    $this->postJson('/api/v1/cardholders', ['full_name' => 'Nine A', 'custom_fields' => ['class' => 'Nine', 'hostel' => false]]);

    $this->getJson('/api/v1/cardholders?filter[custom][class]=Ten')->assertJsonCount(1, 'data')->assertJsonPath('data.0.full_name', 'Ten A');
    $this->getJson('/api/v1/cardholders?filter[custom][hostel]=false')->assertJsonCount(1, 'data')->assertJsonPath('data.0.full_name', 'Nine A');
});

it('keeps field definitions per organization and enforces permissions', function () {
    [$otherTenant] = tenantWithOwner('beta', 'b@example.com');
    $foreign = customField($otherTenant, ['key' => 'secret', 'label' => 'Secret', 'type' => 'text']);

    Sanctum::actingAs($this->owner);
    $this->getJson('/api/v1/custom-fields')->assertJsonCount(0, 'data');
    $this->putJson("/api/v1/custom-fields/{$foreign->public_id}", ['label' => 'x'])->assertNotFound();
    $this->postJson('/api/v1/cardholders', ['full_name' => 'X', 'custom_fields' => ['secret' => 'x']])->assertJsonValidationErrors('custom_fields');

    Sanctum::actingAs(tenantUser($this->tenant, 'Operator'));
    $this->getJson('/api/v1/custom-fields')->assertOk();
    $this->postJson('/api/v1/custom-fields', ['entity' => 'cardholder', 'key' => 'x', 'label' => 'X', 'type' => 'text'])->assertForbidden();
    $this->postJson('/api/v1/custom-fields/presets/student')->assertForbidden();
});
