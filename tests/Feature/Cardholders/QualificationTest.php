<?php

use App\Models\Qualification;
use App\Support\Tenancy\TenantContext;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    [$this->tenant, $this->owner] = tenantWithOwner();
    $this->cardholder = cardholder($this->tenant);
    $this->base = "/api/v1/cardholders/{$this->cardholder->public_id}/qualifications";
});

it('records education and training courses', function () {
    Sanctum::actingAs($this->owner);

    $this->postJson($this->base, ['kind' => 'education', 'title' => 'SSC', 'institution' => 'Dhaka Board', 'year' => 2008, 'subject' => 'Science', 'result' => '5.00'])
        ->assertCreated()
        ->assertJsonPath('data.kind', 'education')
        ->assertJsonPath('data.sort_order', 1);
    $this->postJson($this->base, ['kind' => 'education', 'title' => 'HSC', 'year' => 2010])->assertCreated()->assertJsonPath('data.sort_order', 2);
    $course = $this->postJson($this->base, ['kind' => 'course', 'title' => 'First aid', 'institution' => 'Red Crescent', 'certificate_no' => 'FA-123'])
        ->assertCreated()
        ->assertJsonPath('data.sort_order', 1)
        ->json('data.id');

    $this->getJson($this->base)->assertJsonCount(3, 'data');
    $this->getJson("{$this->base}?kind=course")->assertJsonCount(1, 'data')->assertJsonPath('data.0.certificate_no', 'FA-123');

    $this->putJson("{$this->base}/{$course}", ['kind' => 'course', 'title' => 'Advanced first aid', 'result' => 'Passed'])
        ->assertOk()
        ->assertJsonPath('data.result', 'Passed');
    $this->deleteJson("{$this->base}/{$course}")->assertNoContent();
    $this->getJson("{$this->base}?kind=course")->assertJsonCount(0, 'data');
});

it('validates qualifications', function () {
    Sanctum::actingAs($this->owner);

    $this->postJson($this->base, ['kind' => 'hobby', 'year' => 1700])->assertJsonValidationErrors(['kind', 'title', 'year']);
});

it('keeps qualifications inside their cardholder and enforces permissions', function () {
    $record = app(TenantContext::class)->run($this->tenant, fn () => Qualification::factory()->create(['tenant_id' => $this->tenant->id, 'cardholder_id' => $this->cardholder->id]));
    $other = cardholder($this->tenant);

    Sanctum::actingAs($this->owner);
    $this->deleteJson("/api/v1/cardholders/{$other->public_id}/qualifications/{$record->public_id}")->assertNotFound();

    Sanctum::actingAs(tenantUser($this->tenant, 'Viewer'));
    $this->getJson($this->base)->assertOk()->assertJsonCount(1, 'data');
    $this->postJson($this->base, ['kind' => 'course', 'title' => 'X'])->assertForbidden();
});
