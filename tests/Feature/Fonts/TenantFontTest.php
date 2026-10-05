<?php

use App\Models\CardTemplate;
use App\Models\TenantFont;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    Storage::fake(config('cardholder_files.disk'));
    [$this->tenant, $this->owner] = tenantWithOwner();
});

/**
 * A minimal font file: the tables a font needs, with the family in a Windows name record.
 *
 * @param  'sfnt'|'woff'|'otto'  $container
 */
function fontFile(string $family = 'Test Lipi', string $container = 'sfnt', string $name = 'font.ttf'): UploadedFile
{
    $utf16 = mb_convert_encoding($family, 'UTF-16BE', 'UTF-8');
    $tables = [
        'cmap' => str_repeat("\0", 4),
        'glyf' => str_repeat("\0", 4),
        'head' => str_repeat("\0", 4),
        'name' => pack('nnn', 0, 1, 18).pack('nnnnnn', 3, 1, 0x409, 1, strlen($utf16), 0).$utf16,
    ];

    if ($container === 'woff') {
        $offset = 44 + count($tables) * 20;
        [$directory, $data] = ['', ''];
        foreach ($tables as $tag => $table) {
            $directory .= $tag.pack('NNNN', $offset + strlen($data), strlen($table), strlen($table), 0);
            $data .= $table;
        }
        $length = $offset + strlen($data);
        $bytes = 'wOFF'.pack('NNnnNnnNNNNN', 0x00010000, $length, count($tables), 0, 0, 1, 0, 0, 0, 0, 0, 0).$directory.$data;
    } else {
        $offset = 12 + count($tables) * 16;
        [$directory, $data] = ['', ''];
        foreach ($tables as $tag => $table) {
            $directory .= $tag.pack('NNN', 0, $offset + strlen($data), strlen($table));
            $data .= $table;
        }
        $bytes = ($container === 'otto' ? 'OTTO' : "\x00\x01\x00\x00").pack('nnnn', count($tables), 0, 0, 0).$directory.$data;
    }

    return UploadedFile::fake()->createWithContent($name, $bytes);
}

it('uploads a font, reads its family name and serves it through a signed link', function () {
    Sanctum::actingAs($this->owner);

    $response = $this->post('/api/v1/fonts', ['file' => fontFile('Solaiman Test'), 'licence_confirmed' => '1'], ['Accept' => 'application/json'])
        ->assertCreated()
        ->assertJsonPath('data.family', 'Solaiman Test')
        ->assertJsonPath('data.weight', 400)
        ->assertJsonPath('data.style', 'normal')
        ->assertJsonPath('data.format', 'truetype');

    $font = TenantFont::query()->sole();
    expect($font->path)->toStartWith("tenants/{$this->tenant->public_id}/fonts/")->toEndWith('.ttf');
    Storage::disk(config('cardholder_files.disk'))->assertExists($font->path);

    $this->getJson('/api/v1/fonts')->assertOk()->assertJsonCount(1, 'data');

    $url = $response->json('data.url');
    $this->get($url)->assertOk()->assertHeader('Content-Type', 'font/ttf')->assertHeader('X-Content-Type-Options', 'nosniff');
    $this->get(strtok($url, '?').'?v='.substr(sha1($font->path), 0, 12))->assertForbidden();
});

it('takes a given family, weight and style and recognizes OpenType and WOFF', function () {
    Sanctum::actingAs($this->owner);
    $upload = fn (array $data) => $this->post('/api/v1/fonts', [...$data, 'licence_confirmed' => true], ['Accept' => 'application/json']);

    $upload(['file' => fontFile('Ignored', 'otto', 'bold.otf'), 'family' => 'Nikosh', 'weight' => 700, 'style' => 'italic'])
        ->assertCreated()
        ->assertJsonPath('data.family', 'Nikosh')
        ->assertJsonPath('data.weight', 700)
        ->assertJsonPath('data.style', 'italic')
        ->assertJsonPath('data.format', 'opentype');
    $upload(['file' => fontFile('Woff Lipi', 'woff', 'web.woff')])->assertCreated()->assertJsonPath('data.family', 'Woff Lipi')->assertJsonPath('data.format', 'woff');

    // Same face again, a built-in name, no licence confirmation, bad weight
    $upload(['file' => fontFile(), 'family' => 'Nikosh', 'weight' => 700, 'style' => 'italic'])->assertJsonValidationErrors('family');
    $upload(['file' => fontFile(), 'family' => 'inter'])->assertJsonValidationErrors('family');
    $this->post('/api/v1/fonts', ['file' => fontFile(), 'weight' => 450], ['Accept' => 'application/json'])
        ->assertJsonValidationErrors(['licence_confirmed', 'weight']);

    expect(TenantFont::query()->pluck('family')->sort()->values()->all())->toBe(['Nikosh', 'Woff Lipi']);
});

it('rejects files that are not single fonts whatever their name', function () {
    Sanctum::actingAs($this->owner);
    $upload = fn (UploadedFile $file) => $this->post('/api/v1/fonts', ['file' => $file, 'licence_confirmed' => true], ['Accept' => 'application/json']);

    $upload(UploadedFile::fake()->image('picture.ttf'))->assertJsonValidationErrors(['file' => __('fonts.unreadable')]);
    $upload(UploadedFile::fake()->createWithContent('family.ttc', 'ttcf'.str_repeat("\0", 40)))->assertJsonValidationErrors(['file' => __('fonts.collection')]);
    // Table directory pointing outside the file
    $upload(UploadedFile::fake()->createWithContent('broken.ttf', "\x00\x01\x00\x00".pack('nnnn', 1, 0, 0, 0).'cmap'.pack('NNN', 0, 9999, 10)))->assertJsonValidationErrors('file');

    expect(TenantFont::count())->toBe(0);
});

it('keeps a font family that card templates use', function () {
    Sanctum::actingAs($this->owner);
    $regular = $this->post('/api/v1/fonts', ['file' => fontFile('Card Lipi'), 'licence_confirmed' => true], ['Accept' => 'application/json'])->json('data.id');
    $bold = $this->post('/api/v1/fonts', ['file' => fontFile('Card Lipi'), 'weight' => 700, 'licence_confirmed' => true], ['Accept' => 'application/json'])->json('data.id');
    app(TenantContext::class)->run($this->tenant, function () {
        $template = CardTemplate::factory()->create(['tenant_id' => $this->tenant->id, 'name' => 'Staff card']);
        $template->elements()->create(['side' => 'front', 'type' => 'text', 'content' => 'Hello', 'x' => 1, 'y' => 1, 'width' => 20, 'height' => 5, 'z_index' => 0, 'style' => ['font_family' => 'Card Lipi']]);
    });

    // Another weight is left, so this one may go; the last file of a used family may not
    $path = TenantFont::query()->where('public_id', $bold)->value('path');
    $this->deleteJson("/api/v1/fonts/{$bold}")->assertNoContent();
    Storage::disk(config('cardholder_files.disk'))->assertMissing($path);
    $this->deleteJson("/api/v1/fonts/{$regular}")
        ->assertUnprocessable()
        ->assertJsonPath('message', __('fonts.in_use', ['family' => 'Card Lipi', 'templates' => 'Staff card']));
});

it('lets template users read fonts and keeps them per organization', function () {
    $font = app(TenantContext::class)->run($this->tenant, fn () => TenantFont::factory()->create(['tenant_id' => $this->tenant->id]));

    Sanctum::actingAs(tenantUser($this->tenant, 'Operator'));
    $this->getJson('/api/v1/fonts')->assertOk()->assertJsonCount(1, 'data');
    $this->post('/api/v1/fonts', ['file' => fontFile(), 'licence_confirmed' => true], ['Accept' => 'application/json'])->assertForbidden();
    $this->deleteJson("/api/v1/fonts/{$font->public_id}")->assertForbidden();

    Sanctum::actingAs(tenantUser($this->tenant, 'Viewer'));
    $this->getJson('/api/v1/fonts')->assertForbidden();

    [, $otherOwner] = tenantWithOwner('other', 'owner@other.test');
    Sanctum::actingAs($otherOwner);
    $this->getJson('/api/v1/fonts')->assertOk()->assertJsonCount(0, 'data');
    $this->deleteJson("/api/v1/fonts/{$font->public_id}")->assertNotFound();
});
