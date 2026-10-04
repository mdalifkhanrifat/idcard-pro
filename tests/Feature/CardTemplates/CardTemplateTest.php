<?php

use App\Models\CardTemplate;
use App\Models\CardTemplateElement;
use App\Models\MasterDataItem;
use App\Support\Tenancy\TenantContext;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    [$this->tenant, $this->owner] = tenantWithOwner();
});

function familyCardType(): MasterDataItem
{
    return MasterDataItem::where('type', 'card_type')->where('name', 'Family Card')->sole();
}

it('seeds the legacy card types for new organizations', function () {
    expect(MasterDataItem::where('type', 'card_type')->pluck('name')->all())->toBe(['Employee Card', 'Family Card', 'Visitor Card']);
});

it('creates, lists, updates and deletes templates', function () {
    Sanctum::actingAs($this->owner);
    $authority = masterItem($this->tenant, ['type' => 'authority_type', 'name' => 'Head office']);

    $id = $this->postJson('/api/v1/card-templates', [
        'name' => 'Family card 2026', 'code' => 'fam', 'card_type_id' => familyCardType()->public_id, 'audience' => 'family_member',
        'authority_type_id' => $authority->public_id, 'validity_months' => 24,
    ])
        ->assertCreated()
        ->assertJsonPath('data.code', 'FAM')
        ->assertJsonPath('data.width', 85.6)
        ->assertJsonPath('data.height', 54)
        ->assertJsonPath('data.size_unit', 'mm')
        ->assertJsonPath('data.card_type.name', 'Family Card')
        ->assertJsonPath('data.authority_type.name', 'Head office')
        ->assertJsonPath('data.version', 1)
        ->json('data.id');

    $this->getJson('/api/v1/card-templates?filter[audience]=family_member')->assertJsonCount(1, 'data');
    $this->getJson('/api/v1/card-templates?filter[search]=fam')->assertJsonCount(1, 'data');
    $this->getJson('/api/v1/card-templates?filter[card_type]='.familyCardType()->public_id)->assertJsonCount(1, 'data');

    // Renaming keeps the version; changing the layout raises it.
    $this->putJson("/api/v1/card-templates/{$id}", ['name' => 'Family card'])->assertOk()->assertJsonPath('data.version', 1);
    $this->putJson("/api/v1/card-templates/{$id}", ['orientation' => 'vertical'])->assertOk()->assertJsonPath('data.version', 2);

    $this->deleteJson("/api/v1/card-templates/{$id}")->assertNoContent();
    expect(CardTemplate::count())->toBe(0);
});

it('duplicates a template as an inactive draft', function () {
    Sanctum::actingAs($this->owner);
    $id = $this->postJson('/api/v1/card-templates', ['name' => 'Staff', 'code' => 'EMP', 'orientation' => 'vertical', 'width' => 54, 'height' => 85.6])->json('data.id');

    $this->postJson("/api/v1/card-templates/{$id}/duplicate", ['name' => 'Staff copy'])
        ->assertCreated()
        ->assertJsonPath('data.name', 'Staff copy')
        ->assertJsonPath('data.orientation', 'vertical')
        ->assertJsonPath('data.width', 54)
        ->assertJsonPath('data.is_active', false);
});

it('copies designer elements when duplicating', function () {
    Sanctum::actingAs($this->owner);
    $template = app(TenantContext::class)->run($this->tenant, fn () => CardTemplate::factory()->create(['tenant_id' => $this->tenant->id]));
    app(TenantContext::class)->run($this->tenant, function () use ($template) {
        CardTemplateElement::factory()->create(['tenant_id' => $this->tenant->id, 'card_template_id' => $template->id, 'field' => 'full_name']);
        CardTemplateElement::factory()->create(['tenant_id' => $this->tenant->id, 'card_template_id' => $template->id, 'side' => 'back', 'type' => 'qr_code', 'field' => null, 'qr_lines' => [['field' => 'employee_no', 'label' => 'ID'], ['text' => 'Property of the organization']]]);
    });

    $copyId = $this->postJson("/api/v1/card-templates/{$template->public_id}/duplicate", ['name' => 'Copy'])->assertCreated()->json('data.id');

    $copy = CardTemplate::where('public_id', $copyId)->sole();
    expect($copy->elements)->toHaveCount(2)
        ->and($copy->elements->pluck('type')->sort()->values()->all())->toBe(['field', 'qr_code'])
        ->and($copy->elements->first()->style['font_family'])->toBe('Inter')
        ->and($copy->elements->firstWhere('type', 'qr_code')->qr_lines[1]['text'])->toBe('Property of the organization')
        ->and(CardTemplateElement::count())->toBe(4);
});

it('validates templates', function () {
    Sanctum::actingAs($this->owner);
    $rank = masterItem($this->tenant, ['type' => 'rank', 'name' => 'X']);

    $this->postJson('/api/v1/card-templates', ['code' => 'A B', 'card_type_id' => $rank->public_id, 'width' => -1, 'orientation' => 'diagonal', 'validity_months' => 0])
        ->assertJsonValidationErrors(['name', 'code', 'card_type_id', 'width', 'orientation', 'validity_months']);
});

it('protects card types used by templates', function () {
    Sanctum::actingAs($this->owner);
    $this->postJson('/api/v1/card-templates', ['name' => 'F', 'code' => 'F', 'card_type_id' => familyCardType()->public_id])->assertCreated();

    $this->deleteJson('/api/v1/master-data/card_type/'.familyCardType()->public_id)->assertUnprocessable();
});

it('keeps templates per organization and enforces permissions', function () {
    [$otherTenant] = tenantWithOwner('beta', 'b@example.com');
    $foreign = app(TenantContext::class)->run($otherTenant, fn () => CardTemplate::factory()->create(['tenant_id' => $otherTenant->id]));

    Sanctum::actingAs($this->owner);
    $this->getJson('/api/v1/card-templates')->assertJsonCount(0, 'data');
    $this->putJson("/api/v1/card-templates/{$foreign->public_id}", ['name' => 'x'])->assertNotFound();

    Sanctum::actingAs(tenantUser($this->tenant, 'Operator'));
    $this->getJson('/api/v1/card-templates')->assertOk();
    $this->postJson('/api/v1/card-templates', ['name' => 'A', 'code' => 'A'])->assertForbidden();
});

it('uses the organization default card size for new templates', function () {
    Sanctum::actingAs($this->owner);
    $settings = ['name' => 'Acme', 'timezone' => 'UTC', 'locale' => 'en', 'primary_color' => '#112233'];

    $this->post('/api/v1/settings', [...$settings, 'default_card_size' => ['width' => 90, 'height' => 55]], ['Accept' => 'application/json'])
        ->assertOk()
        ->assertJsonPath('data.default_card_size', ['width' => 90, 'height' => 55])
        ->assertJsonPath('data.card_number_format', '{CODE}/{YY}{SEQ:6}');

    $this->postJson('/api/v1/card-templates', ['name' => 'Wide', 'code' => 'W'])->assertJsonPath('data.width', 90)->assertJsonPath('data.height', 55);
    $this->postJson('/api/v1/card-templates', ['name' => 'Tall', 'code' => 'T', 'orientation' => 'vertical'])->assertJsonPath('data.width', 55)->assertJsonPath('data.height', 90);

    $this->post('/api/v1/settings', [...$settings, 'default_card_size' => ['width' => 40, 'height' => 60]], ['Accept' => 'application/json'])
        ->assertJsonValidationErrors('default_card_size.width');
});
