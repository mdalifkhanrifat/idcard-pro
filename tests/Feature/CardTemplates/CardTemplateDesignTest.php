<?php

use App\Models\CardTemplate;
use App\Models\CardTemplateElement;
use App\Models\TenantFont;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    Storage::fake(config('cardholder_files.disk'));
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
    $this->putJson($this->url, ['elements' => [designElement(['style' => ['font_family' => 'Inter', 'font_size' => 9, 'color' => '#111111', 'bold' => true, 'align' => 'left', 'locked' => true]])]])
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

    $this->post("/api/v1/card-templates/{$foreign->public_id}/images", ['file' => UploadedFile::fake()->image('l.png')], ['Accept' => 'application/json'])->assertNotFound();

    Sanctum::actingAs(tenantUser($this->tenant, 'Operator'));
    $this->getJson($this->url)->assertOk();
    $this->putJson($this->url, ['elements' => []])->assertForbidden();
    $this->post("/api/v1/card-templates/{$this->template->public_id}/images", ['file' => UploadedFile::fake()->image('l.png')], ['Accept' => 'application/json'])->assertForbidden();
});

it('uploads logo and background images and cleans up replaced ones', function () {
    Sanctum::actingAs($this->owner);
    $upload = fn (string $name) => $this->post("/api/v1/card-templates/{$this->template->public_id}/images", ['file' => UploadedFile::fake()->image($name, 600, 300)], ['Accept' => 'application/json'])
        ->assertCreated()
        ->json('data');

    $logo = $upload('logo.png');
    $background = $upload('bg.jpg');
    expect($logo['image'])->toMatch('/^[0-9a-z]{26}\.(png|jpg)$/');
    $this->get($logo['image_url'])->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');

    $this->putJson($this->url, ['elements' => [
        designElement(['type' => 'background', 'field' => null, 'image' => $background['image'], 'x' => 0, 'y' => 0, 'width' => 85.6, 'height' => 54]),
        designElement(['type' => 'logo', 'field' => null, 'image' => $logo['image'], 'x' => 2, 'y' => 2, 'width' => 12, 'height' => 12]),
        designElement(['type' => 'photo', 'image' => $logo['image']]),
        designElement(['type' => 'qr_code', 'image' => $logo['image']]),
    ]])
        ->assertOk()
        ->assertJsonPath('data.1.image', $logo['image'])
        // Photos keep a fallback image (legacy profile upload); QR codes have none.
        ->assertJsonPath('data.2.image', $logo['image'])
        ->assertJsonPath('data.3.image', null)
        ->assertJsonPath('data.3.image_url', null);
    $logoUrl = $this->getJson($this->url)->json('data.1.image_url');
    $this->get($logoUrl)->assertOk();

    // A file of another template (or a made-up name) cannot be referenced.
    $this->putJson($this->url, ['elements' => [designElement(['type' => 'logo', 'field' => null, 'image' => str_repeat('a', 26).'.png'])]])
        ->assertJsonValidationErrors('elements.0.image');

    // Dropping the logo deletes its file; the background stays.
    $this->putJson($this->url, ['elements' => [
        designElement(['type' => 'background', 'field' => null, 'image' => $background['image'], 'x' => 0, 'y' => 0, 'width' => 85.6, 'height' => 54]),
    ]])->assertOk();
    $this->get($logoUrl)->assertNotFound();
    expect(Storage::disk(config('cardholder_files.disk'))->allFiles())->toHaveCount(1);

    // Tampered links are refused.
    $this->get(str_replace($background['image'], $logo['image'], $background['image_url']))->assertForbidden();

    $this->post("/api/v1/card-templates/{$this->template->public_id}/images", ['file' => UploadedFile::fake()->createWithContent('x.png', '%PDF-1.4')], ['Accept' => 'application/json'])
        ->assertUnprocessable();
});

it('gives a duplicated template its own images and deletes them with the template', function () {
    Sanctum::actingAs($this->owner);
    $logo = $this->post("/api/v1/card-templates/{$this->template->public_id}/images", ['file' => UploadedFile::fake()->image('logo.png')], ['Accept' => 'application/json'])->json('data.image');
    $this->putJson($this->url, ['elements' => [designElement(['type' => 'logo', 'field' => null, 'image' => $logo])]])->assertOk();

    $copyId = $this->postJson("/api/v1/card-templates/{$this->template->public_id}/duplicate", ['name' => 'Copy'])->json('data.id');
    $disk = Storage::disk(config('cardholder_files.disk'));
    expect($disk->allFiles())->toHaveCount(2);

    // Removing the logo from the copy leaves the original intact.
    $this->putJson("/api/v1/card-templates/{$copyId}/elements", ['elements' => []])->assertOk();
    expect($disk->allFiles())->toHaveCount(1);
    $this->get($this->getJson($this->url)->json('data.0.image_url'))->assertOk();

    $this->deleteJson("/api/v1/card-templates/{$this->template->public_id}")->assertNoContent();
    expect($disk->allFiles())->toHaveCount(0);
});

it('saves shapes, lines, colour backgrounds and box styles', function () {
    Sanctum::actingAs($this->owner);

    $this->putJson($this->url, ['elements' => [
        designElement(['type' => 'background', 'field' => null, 'x' => 0, 'y' => 0, 'width' => 85.6, 'height' => 54, 'style' => [
            'background' => '#0f62fe', 'gradient_to' => '#ffffff', 'gradient_direction' => 'to bottom',
        ]]),
        designElement(['type' => 'shape', 'field' => null, 'x' => 0, 'y' => 0, 'width' => 85.6, 'height' => 10, 'style' => ['background' => '#111111', 'opacity' => '0.8']]),
        designElement(['type' => 'shape', 'field' => null, 'x' => 5, 'y' => 30, 'width' => 75, 'height' => 0.3, 'style' => ['background' => '#cccccc']]),
        designElement(['type' => 'photo', 'field' => null, 'x' => 3, 'y' => 12, 'width' => 22, 'height' => 22, 'style' => [
            'border_radius' => 11, 'border_width' => '0.5', 'border_color' => '#ffffff', 'fit' => 'cover',
        ]]),
        designElement(['style' => ['uppercase' => '1', 'vertical_align' => 'middle', 'line_height' => 1.2, 'letter_spacing' => 0.5, 'padding' => 1]]),
    ]])
        ->assertOk()
        ->assertJsonPath('data.0.style.gradient_to', '#ffffff')
        ->assertJsonPath('data.1.type', 'shape')
        ->assertJsonPath('data.1.style.opacity', 0.8)
        ->assertJsonPath('data.2.height', 0.3)
        ->assertJsonPath('data.3.style.border_radius', 11)
        ->assertJsonPath('data.4.style.uppercase', true);

    $this->putJson($this->url, ['elements' => [
        designElement(['type' => 'shape', 'field' => null, 'style' => ['shape' => 'star', 'opacity' => 0, 'gradient_direction' => 'sideways', 'fit' => 'stretch']]),
    ]])->assertJsonValidationErrors(['elements.0.style.shape', 'elements.0.style.opacity', 'elements.0.style.gradient_direction', 'elements.0.style.fit']);
});

it('offers bundled fonts and legacy system fonts', function () {
    Sanctum::actingAs($this->owner);

    $this->putJson($this->url, ['elements' => [
        designElement(['style' => ['font_family' => 'Hind Siliguri']]),
        designElement(['style' => ['font_family' => 'SutonnyMJ']]),
    ]])->assertOk();

    $this->getJson($this->url)
        ->assertJsonPath('meta.catalog.fonts.2', 'Noto Sans Bengali')
        ->assertJsonPath('meta.catalog.system_fonts.3', 'SutonnyMJ');
});

it('lists templates with their layout for thumbnails', function () {
    Sanctum::actingAs($this->owner);
    $this->putJson($this->url, ['elements' => [designElement(), designElement(['side' => 'back'])]])->assertOk();

    $this->getJson('/api/v1/card-templates?include=elements')
        ->assertOk()
        ->assertJsonCount(2, 'data.0.elements')
        ->assertJsonPath('data.0.elements.1.field', 'full_name');
    $this->getJson('/api/v1/card-templates')->assertJsonMissingPath('data.0.elements');
    $this->getJson('/api/v1/card-templates?include=everything')->assertJsonValidationErrors('include');
});

it('keeps rotation, border style, locked and hidden flags', function () {
    Sanctum::actingAs($this->owner);

    $this->putJson($this->url, ['elements' => [
        designElement(['style' => ['rotation' => '90', 'border_style' => 'dashed', 'border_width' => 0.3, 'locked' => '1', 'hidden' => false, 'auto_fit' => '1', 'single_line' => true]]),
    ]])
        ->assertOk()
        ->assertJsonPath('data.0.style.auto_fit', true)
        ->assertJsonPath('data.0.style.single_line', true)
        ->assertJsonPath('data.0.style.rotation', 90)
        ->assertJsonPath('data.0.style.border_style', 'dashed')
        ->assertJsonPath('data.0.style.locked', true)
        ->assertJsonMissingPath('data.0.style.hidden');

    $this->putJson($this->url, ['elements' => [designElement(['style' => ['rotation' => 45, 'border_style' => 'double']])]])
        ->assertJsonValidationErrors(['elements.0.style.rotation', 'elements.0.style.border_style']);
});

it('saves barcodes of a data field with their format and text option', function () {
    Sanctum::actingAs($this->owner);

    $this->putJson($this->url, ['elements' => [
        designElement(['type' => 'barcode', 'field' => 'card.number', 'label' => null, 'height' => 10, 'style' => ['barcode_format' => 'code39', 'barcode_text' => '1', 'color' => '#000000']]),
        designElement(['type' => 'barcode', 'field' => 'employee_no', 'label' => null, 'style' => ['barcode_text' => false]]),
    ]])
        ->assertOk()
        ->assertJsonPath('data.0.type', 'barcode')
        ->assertJsonPath('data.0.field', 'card.number')
        ->assertJsonPath('data.0.style.barcode_format', 'code39')
        ->assertJsonPath('data.0.style.barcode_text', true)
        ->assertJsonMissingPath('data.1.style.barcode_text');

    $this->getJson($this->url)->assertJsonPath('meta.catalog.barcode_formats.0', 'code128');

    $this->putJson($this->url, ['elements' => [
        designElement(['type' => 'barcode', 'field' => null]),
        designElement(['type' => 'barcode', 'field' => 'family.name']),
    ]])->assertJsonValidationErrors(['elements.0.field', 'elements.1.field']);
    $this->putJson($this->url, ['elements' => [designElement(['type' => 'barcode', 'style' => ['barcode_format' => 'qr']])]])
        ->assertJsonValidationErrors(['elements.0.style.barcode_format']);
});

it('offers and accepts the organization own fonts', function () {
    Sanctum::actingAs($this->owner);
    app(TenantContext::class)->run($this->tenant, fn () => TenantFont::factory()->create(['tenant_id' => $this->tenant->id, 'family' => 'Licensed Lipi', 'weight' => 700]));

    $this->putJson($this->url, ['elements' => [designElement(['style' => ['font_family' => 'Licensed Lipi']])]])->assertOk();
    $this->getJson($this->url)
        ->assertJsonPath('meta.catalog.tenant_fonts.0.family', 'Licensed Lipi')
        ->assertJsonPath('meta.catalog.tenant_fonts.0.weight', 700)
        ->assertJson(fn ($json) => $json->where('meta.catalog.tenant_fonts.0.url', fn (string $url) => str_starts_with($url, '/api/v1/font-files/'))->etc());

    $this->putJson($this->url, ['elements' => [designElement(['style' => ['font_family' => 'Unknown Lipi']])]])
        ->assertJsonValidationErrors('elements.0.style.font_family');
});
