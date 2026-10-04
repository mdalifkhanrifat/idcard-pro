<?php

use App\Models\CardTemplate;
use App\Models\CardTemplateElement;
use App\Support\Tenancy\TenantContext;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    [$this->tenant, $this->owner] = tenantWithOwner();
    $this->template = app(TenantContext::class)->run($this->tenant, fn () => CardTemplate::factory()->create([
        'tenant_id' => $this->tenant->id, 'width' => 85.6, 'height' => 54, 'size_unit' => 'mm', 'is_double_sided' => true,
    ]));
    $this->url = "/api/v1/card-templates/{$this->template->public_id}/elements";
});

/**
 * @return array<string, mixed>
 */
function designElement(array $overrides = []): array
{
    return [
        'side' => 'front', 'type' => 'field', 'field' => 'full_name', 'label' => 'Name',
        'x' => 30, 'y' => 20, 'width' => 40, 'height' => 6,
        'style' => ['font_family' => 'Inter', 'font_size' => 9, 'color' => '#111111', 'bold' => true, 'align' => 'left'],
        ...$overrides,
    ];
}

it('saves and returns a whole layout with layer order per side', function () {
    Sanctum::actingAs($this->owner);

    $this->putJson($this->url, ['elements' => [
        designElement(),
        designElement(['type' => 'photo', 'field' => 'ignored', 'label' => null, 'x' => 2, 'y' => 10, 'width' => 22, 'height' => 28, 'style' => null]),
        designElement(['side' => 'back', 'type' => 'text', 'field' => null, 'content' => 'If found, please return.', 'x' => 5, 'y' => 40]),
        designElement(['side' => 'back', 'type' => 'qr_code', 'field' => null, 'x' => 60, 'y' => 5, 'width' => 20, 'height' => 20, 'qr_lines' => [
            ['field' => 'employee_no', 'label' => 'ID'],
            ['text' => 'Acme Ltd'],
        ]]),
    ]])
        ->assertOk()
        ->assertJsonCount(4, 'data')
        ->assertJsonPath('meta.version', 2)
        ->assertJsonPath('data.0.side', 'back')
        ->assertJsonPath('data.0.content', 'If found, please return.')
        ->assertJsonPath('data.1.qr_lines', [['field' => 'employee_no', 'label' => 'ID'], ['text' => 'Acme Ltd']])
        ->assertJsonPath('data.1.z_index', 1)
        ->assertJsonPath('data.2.field', 'full_name')
        ->assertJsonPath('data.2.x', 30)
        ->assertJsonPath('data.2.style.bold', true)
        ->assertJsonPath('data.3.type', 'photo')
        ->assertJsonPath('data.3.field', null)
        ->assertJsonPath('data.3.z_index', 1);

    $this->getJson($this->url)
        ->assertOk()
        ->assertJsonCount(4, 'data')
        ->assertJsonPath('meta.version', 2)
        ->assertJsonPath('meta.catalog.size_mm', ['width' => 85.6, 'height' => 54])
        ->assertJsonPath('meta.catalog.fields.card.0', 'card.number')
        ->assertJsonMissingPath('meta.catalog.fields.family_member');
});

it('raises the version only when the design changes', function () {
    Sanctum::actingAs($this->owner);

    $this->putJson($this->url, ['elements' => [designElement()]])->assertJsonPath('meta.version', 2);
    $this->putJson($this->url, ['elements' => [designElement(['style' => ['font_family' => 'Inter', 'font_size' => '9', 'color' => '#111111', 'bold' => '1', 'align' => 'left']])]])
        ->assertJsonPath('meta.version', 2);
    $this->putJson($this->url, ['elements' => [designElement(['x' => 31])]])->assertJsonPath('meta.version', 3);
    $this->putJson($this->url, ['elements' => []])->assertJsonPath('meta.version', 4)->assertJsonCount(0, 'data');

    expect(CardTemplateElement::count())->toBe(0);
});

it('checks fields against the template audience and custom fields', function () {
    Sanctum::actingAs($this->owner);
    $this->postJson('/api/v1/custom-fields', ['entity' => 'cardholder', 'key' => 'class', 'label' => 'Class', 'type' => 'text'])->assertCreated();

    $this->putJson($this->url, ['elements' => [
        designElement(['field' => 'custom.class']),
        designElement(['field' => 'family.name']),
        designElement(['field' => 'custom.missing']),
        designElement(['field' => null]),
    ]])->assertJsonValidationErrors(['elements.1.field', 'elements.2.field', 'elements.3.field'])
        ->assertJsonMissingValidationErrors(['elements.0.field']);

    $this->template->update(['audience' => 'family_member']);
    $this->putJson($this->url, ['elements' => [designElement(['field' => 'family.name'])]])->assertOk();
    $this->getJson($this->url)
        ->assertJsonPath('meta.catalog.fields.family_member.0', 'family.name')
        ->assertJsonPath('meta.catalog.custom_fields.0.key', 'custom.class');
});

it('validates layout rules', function () {
    Sanctum::actingAs($this->owner);
    $background = ['type' => 'background', 'field' => null, 'x' => 0, 'y' => 0, 'width' => 85.6, 'height' => 54];

    $this->putJson($this->url, ['elements' => [
        designElement($background),
        designElement($background),
        designElement(['x' => 50, 'width' => 40]),
        designElement(['type' => 'text', 'field' => null, 'content' => '  ']),
        designElement(['type' => 'qr_code', 'field' => null, 'qr_lines' => [['label' => 'only a label']]]),
    ]])->assertJsonValidationErrors(['elements.1.type', 'elements.2.x', 'elements.3.content', 'elements.4.qr_lines.0'])
        ->assertJsonMissingValidationErrors(['elements.0.type']);

    $this->putJson($this->url, ['elements' => [
        designElement(['style' => ['font_family' => 'Comic Sans', 'color' => 'red', 'shadow' => true]]),
        designElement(['type' => 'hologram', 'width' => 0]),
    ]])->assertJsonValidationErrors(['elements.0.style', 'elements.0.style.font_family', 'elements.0.style.color', 'elements.1.type', 'elements.1.width']);

    $this->template->update(['is_double_sided' => false]);
    $this->putJson($this->url, ['elements' => [designElement(['side' => 'back'])]])->assertJsonValidationErrors('elements.0.side');

    expect(CardTemplateElement::count())->toBe(0);
});

it('measures px templates in millimetres', function () {
    Sanctum::actingAs($this->owner);
    // 332 x 214 px (legacy default) is about 87.84 x 56.62 mm at 96 dpi.
    $this->template->update(['size_unit' => 'px', 'width' => 332, 'height' => 214]);

    $this->putJson($this->url, ['elements' => [designElement(['x' => 47, 'width' => 40])]])->assertOk();
    $this->putJson($this->url, ['elements' => [designElement(['x' => 48, 'width' => 40])]])->assertJsonValidationErrors('elements.0.x');
});

it('keeps layouts per organization and enforces permissions', function () {
    [$otherTenant] = tenantWithOwner('beta', 'b@example.com');
    $foreign = app(TenantContext::class)->run($otherTenant, fn () => CardTemplate::factory()->create(['tenant_id' => $otherTenant->id]));

    Sanctum::actingAs($this->owner);
    $this->getJson("/api/v1/card-templates/{$foreign->public_id}/elements")->assertNotFound();
    $this->putJson("/api/v1/card-templates/{$foreign->public_id}/elements", ['elements' => []])->assertNotFound();

    Sanctum::actingAs(tenantUser($this->tenant, 'Operator'));
    $this->getJson($this->url)->assertOk();
    $this->putJson($this->url, ['elements' => []])->assertForbidden();
});
