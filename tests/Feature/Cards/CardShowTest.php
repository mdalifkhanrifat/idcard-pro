<?php

use App\Models\TenantImage;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    Storage::fake(config('cardholder_files.disk'));
    [$this->tenant, $this->owner] = tenantWithOwner();
    $this->holder = cardholder($this->tenant, ['full_name' => 'Jane Doe']);
    $this->template = cardTemplate($this->tenant, ['code' => 'EMP', 'validity_months' => 12]);
    Sanctum::actingAs($this->owner);
});

it('prints what the card was issued with, even after the cardholder changes', function () {
    $card = $this->postJson("/api/v1/cardholders/{$this->holder->public_id}/cards", ['template' => $this->template->public_id, 'reason' => 'new', 'issue_date' => '2026-01-10'])->json('data');
    $this->holder->update(['full_name' => 'Jane Smith']);

    $response = $this->getJson("/api/v1/cards/{$card['id']}")
        ->assertOk()
        ->assertJsonPath('data.cardholder.full_name', 'Jane Smith')
        ->assertJsonPath('meta.layout.elements.0.field', 'full_name')
        ->assertJsonPath('meta.layout.size_mm.width', 85.6);
    // Keys with dots cannot be reached with assertJsonPath
    expect($response->json('meta.values.fields'))->toMatchArray([
        'full_name' => 'Jane Doe',
        'card.number' => 'EMP/26000001',
        'card.issue_date' => '10/01/2026',
        'card.expiry_date' => '09/01/2027',
    ]);
});

it('keeps template images that issued cards print and links library images', function () {
    $url = "/api/v1/card-templates/{$this->template->public_id}/elements";
    $logo = $this->post("/api/v1/card-templates/{$this->template->public_id}/images", ['file' => UploadedFile::fake()->image('logo.png')], ['Accept' => 'application/json'])->json('data.image');
    $library = app(TenantContext::class)->run($this->tenant, fn () => TenantImage::factory()->create(['tenant_id' => $this->tenant->id]));
    $element = fn (array $extra) => ['side' => 'front', 'type' => 'logo', 'x' => 1, 'y' => 1, 'width' => 10, 'height' => 10, ...$extra];
    $this->putJson($url, ['elements' => [$element(['image' => $logo]), $element(['library_image' => $library->public_id])]])->assertOk();

    $card = $this->postJson("/api/v1/cardholders/{$this->holder->public_id}/cards", ['template' => $this->template->public_id, 'reason' => 'new'])->json('data');
    // The template drops its logo: the issued card still needs the file
    $this->putJson($url, ['elements' => []])->assertOk();
    $path = "tenants/{$this->tenant->public_id}/card-templates/{$this->template->public_id}/{$logo}";
    Storage::disk(config('cardholder_files.disk'))->assertExists($path);

    $response = $this->getJson("/api/v1/cards/{$card['id']}")
        ->assertJsonPath('meta.layout.elements.0.image', $logo)
        ->assertJsonPath('meta.layout.elements.1.library_image', $library->public_id);
    $this->get($response->json('meta.layout.elements.0.image_url'))->assertOk();
    expect($response->json('meta.layout.elements.1.image_url'))->toStartWith('/api/v1/library-image-files/');

    // A logo no card uses is still cleaned up
    $unused = $this->post("/api/v1/card-templates/{$this->template->public_id}/images", ['file' => UploadedFile::fake()->image('b.png')], ['Accept' => 'application/json'])->json('data.image');
    $this->putJson($url, ['elements' => [$element(['image' => $unused])]])->assertOk();
    $this->putJson($url, ['elements' => []])->assertOk();
    Storage::disk(config('cardholder_files.disk'))->assertMissing("tenants/{$this->tenant->public_id}/card-templates/{$this->template->public_id}/{$unused}");
});

it('shows cards to their organization only', function () {
    $card = $this->postJson("/api/v1/cardholders/{$this->holder->public_id}/cards", ['template' => $this->template->public_id, 'reason' => 'new'])->json('data');

    Sanctum::actingAs(tenantUser($this->tenant, 'Viewer'));
    $this->getJson("/api/v1/cards/{$card['id']}")->assertOk();

    [, $otherOwner] = tenantWithOwner('other', 'owner@other.test');
    Sanctum::actingAs($otherOwner);
    $this->getJson("/api/v1/cards/{$card['id']}")->assertNotFound();
});

it('warns about what will print blank: missing photos, barcodes that cannot be encoded and empty or too long QR codes', function () {
    $holder = cardholder($this->tenant, ['employee_no' => 'ACME-0031', 'national_id' => null]);
    app(TenantContext::class)->run($this->tenant, function () {
        $qr = fn (array $lines, array $style) => ['side' => 'back', 'type' => 'qr_code', 'qr_lines' => $lines, 'x' => 5, 'y' => 5, 'width' => 20, 'height' => 20, 'z_index' => 1, 'style' => $style];
        $barcode = fn (string $field, array $style) => ['side' => 'front', 'type' => 'barcode', 'field' => $field, 'x' => 5, 'y' => 30, 'width' => 30, 'height' => 8, 'z_index' => 1, 'style' => $style];
        $this->template->elements()->createMany([
            ['side' => 'front', 'type' => 'photo', 'x' => 60, 'y' => 5, 'width' => 20, 'height' => 25, 'z_index' => 1, 'style' => []],
            $barcode('employee_no', ['barcode_format' => 'ean13']),
            $barcode('employee_no', ['barcode_format' => 'ean13']),
            $barcode('employee_no', ['barcode_format' => 'code128']),
            $barcode('national_id', ['barcode_format' => 'code39']),
            $barcode('employee_no', ['barcode_format' => 'ean8', 'hidden' => true]),
            // QR codes: printable, nothing to encode, too long at H but fine at L, hidden
            $qr([['field' => 'employee_no', 'label' => 'ID'], ['text' => 'কার্ড']], []),
            $qr([['field' => 'national_id', 'label' => 'NID']], []),
            $qr([['text' => str_repeat('x', 1270)], ['field' => 'employee_no', 'label' => 'ID']], []),
            $qr([['text' => str_repeat('x', 1270)], ['field' => 'employee_no', 'label' => 'ID']], ['qr_error_correction' => 'L']),
            $qr([], ['hidden' => true]),
        ]);
    });
    $expected = [
        ['type' => 'missing_image', 'image' => 'photo'],
        ['type' => 'barcode', 'field' => 'employee_no', 'format' => 'ean13', 'value' => 'ACME-0031', 'reason' => 'invalid'],
        ['type' => 'barcode', 'field' => 'national_id', 'format' => 'code39', 'value' => '', 'reason' => 'empty'],
        ['type' => 'qr', 'reason' => 'empty', 'level' => 'H', 'bytes' => 0],
        // 1270 x, a line break and "ID:ACME-0031"
        ['type' => 'qr', 'reason' => 'too_long', 'level' => 'H', 'bytes' => 1283],
    ];

    // A system issuing through the API learns it at once; issuing is not blocked
    // (in the layout's order, which keeps each side's elements together)
    $issued = $this->postJson("/api/v1/cardholders/{$holder->public_id}/cards", ['template' => $this->template->public_id, 'reason' => 'new'])->assertCreated();
    expect($issued->json('meta.warnings'))->toEqualCanonicalizing($expected);

    expect($this->getJson("/api/v1/cards/{$issued->json('data.id')}")->assertOk()->json('meta.warnings'))->toEqualCanonicalizing($expected);
});
