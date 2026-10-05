<?php

use App\Models\CardTemplate;
use App\Models\FamilyMember;
use App\Models\Signatory;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    [$this->tenant, $this->owner] = tenantWithOwner();
    $this->authority = masterItem($this->tenant, ['type' => 'authority_type', 'name' => 'Head office']);
    $this->template = app(TenantContext::class)->run($this->tenant, fn () => CardTemplate::factory()->create([
        'tenant_id' => $this->tenant->id, 'authority_type_id' => $this->authority->id,
    ]));
});

function previewSignatory(array $attributes): Signatory
{
    return app(TenantContext::class)->run(test()->tenant, fn () => Signatory::factory()->create([
        'tenant_id' => test()->tenant->id, 'authority_type_id' => test()->authority->id, ...$attributes,
    ]));
}

it('resolves the printed values of a cardholder in the organization language', function () {
    Sanctum::actingAs($this->owner);
    $this->tenant->update(['locale' => 'bn']);
    $department = masterItem($this->tenant, ['type' => 'department', 'name' => 'Operations']);
    $person = cardholder($this->tenant, [
        'full_name' => 'Jane Doe', 'date_of_birth' => '1990-05-14', 'gender' => 'female', 'employee_no' => 'E-1',
        'department_id' => $department->id, 'national_id' => null, 'custom_fields' => ['class' => 'Seven', 'hostel' => true],
    ]);
    previewSignatory(['name' => 'Retired Head', 'is_active' => true, 'effective_to' => now()->subDay()]);
    previewSignatory(['name' => 'Inactive Head', 'is_active' => false]);
    previewSignatory(['name' => 'Current Head', 'designation' => 'Director', 'is_active' => true, 'effective_from' => now()->subYear()]);

    $data = $this->getJson("/api/v1/card-templates/{$this->template->public_id}/preview?cardholder={$person->public_id}")->assertOk()->json('data');

    expect($data['fields'])->toMatchArray([
        'full_name' => 'Jane Doe',
        'date_of_birth' => '14/05/1990',
        'gender' => 'নারী',
        'department' => 'Operations',
        'custom.class' => 'Seven',
        'custom.hostel' => 'হ্যাঁ',
        'signatory.name' => 'Current Head',
        'signatory.designation' => 'Director',
        'organization.name' => 'Acme',
    ])
        ->and($data['fields'])->not->toHaveKeys(['national_id', 'card.number'])
        ->and($data['images'])->toBe(['photo' => null, 'cardholder_signature' => null, 'signatory_signature' => null]);
});

it('links the current photo and signatures', function () {
    Storage::fake(config('cardholder_files.disk'));
    Sanctum::actingAs($this->owner);
    $person = cardholder($this->tenant);
    $signatory = previewSignatory(['is_active' => true]);
    $upload = fn (string $url, array $data) => $this->post($url, [...$data, 'file' => UploadedFile::fake()->image('i.png', 300, 300)], ['Accept' => 'application/json'])->assertSuccessful();
    $upload("/api/v1/cardholders/{$person->public_id}/files", ['collection' => 'photo']);
    $upload("/api/v1/cardholders/{$person->public_id}/files", ['collection' => 'signature']);
    $upload("/api/v1/signatories/{$signatory->public_id}/signature", []);

    $images = $this->getJson("/api/v1/card-templates/{$this->template->public_id}/preview?cardholder={$person->public_id}")->json('data.images');

    expect($images)->each->toBeString();
    $this->get($images['photo'])->assertOk();
    $this->get($images['signatory_signature'])->assertOk();

    // A family card prints the member's own photo and signature, never the cardholder's
    $member = app(TenantContext::class)->run($this->tenant, fn () => FamilyMember::factory()->create(['tenant_id' => $this->tenant->id, 'cardholder_id' => $person->id]));
    $familyUrl = "/api/v1/card-templates/{$this->template->public_id}/preview?cardholder={$person->public_id}&family_member={$member->public_id}";
    expect($this->getJson($familyUrl)->json('data.images'))->toMatchArray(['photo' => null, 'cardholder_signature' => null]);

    $upload("/api/v1/cardholders/{$person->public_id}/files", ['collection' => 'photo', 'family_member' => $member->public_id]);
    $images = $this->getJson($familyUrl)->json('data.images');
    expect($images['photo'])->toContain($member->fresh()->photo->public_id)
        ->and($images['cardholder_signature'])->toBeNull();
});

it('resolves a family member of the chosen cardholder only', function () {
    Sanctum::actingAs($this->owner);
    $person = cardholder($this->tenant, ['full_name' => 'Jane Doe']);
    $other = cardholder($this->tenant);
    [$spouse, $stranger] = app(TenantContext::class)->run($this->tenant, fn () => [
        FamilyMember::factory()->create(['tenant_id' => $this->tenant->id, 'cardholder_id' => $person->id, 'name' => 'John Doe', 'relation' => 'spouse', 'date_of_birth' => '1988-09-02']),
        FamilyMember::factory()->create(['tenant_id' => $this->tenant->id, 'cardholder_id' => $other->id]),
    ]);
    $url = "/api/v1/card-templates/{$this->template->public_id}/preview?cardholder={$person->public_id}";

    expect($this->getJson("{$url}&family_member={$spouse->public_id}")->assertOk()->json('data.fields'))->toMatchArray([
        'family.name' => 'John Doe',
        'family.relation' => 'Spouse',
        'family.date_of_birth' => '02/09/1988',
        'full_name' => 'Jane Doe',
    ]);

    $this->getJson("{$url}&family_member={$stranger->public_id}")->assertNotFound();
});

it('keeps previews inside the organization', function () {
    [$otherTenant] = tenantWithOwner('beta', 'b@example.com');
    $foreign = cardholder($otherTenant);

    Sanctum::actingAs($this->owner);
    $this->getJson("/api/v1/card-templates/{$this->template->public_id}/preview?cardholder={$foreign->public_id}")->assertNotFound();
    $this->getJson("/api/v1/card-templates/{$this->template->public_id}/preview")->assertJsonValidationErrors('cardholder');
});
