<?php

use App\Models\CardTemplate;
use App\Models\TenantImage;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    Storage::fake(config('cardholder_files.disk'));
    [$this->tenant, $this->owner] = tenantWithOwner();
});

/**
 * A template with a logo element linked to the library image.
 */
function templateUsingLibraryImage(object $test, TenantImage $image, string $name): CardTemplate
{
    return app(TenantContext::class)->run($test->tenant, function () use ($test, $image, $name) {
        $template = CardTemplate::factory()->create(['tenant_id' => $test->tenant->id, 'name' => $name, 'version' => 3]);
        $template->elements()->create(['side' => 'front', 'type' => 'logo', 'library_image_id' => $image->id, 'x' => 1, 'y' => 1, 'width' => 10, 'height' => 10, 'z_index' => 0]);

        return $template;
    });
}

it('uploads, lists, renames and serves library images', function () {
    Sanctum::actingAs($this->owner);

    $response = $this->post('/api/v1/library-images', ['file' => UploadedFile::fake()->image('Company Logo.png', 600, 300)], ['Accept' => 'application/json'])
        ->assertCreated()
        ->assertJsonPath('data.name', 'Company Logo')
        ->assertJsonPath('data.width', 600)
        ->assertJsonPath('data.height', 300)
        // The fake PNG has no transparency, so the sanitizer stores it as JPEG
        ->assertJsonPath('data.mime_type', 'image/jpeg');
    $image = TenantImage::query()->sole();
    expect($image->path)->toStartWith("tenants/{$this->tenant->public_id}/library/");
    Storage::disk(config('cardholder_files.disk'))->assertExists($image->path);

    $this->post('/api/v1/library-images', ['file' => UploadedFile::fake()->image('band.jpg'), 'name' => 'Blue band'], ['Accept' => 'application/json'])->assertCreated();
    $this->getJson('/api/v1/library-images')->assertJsonCount(2, 'data')->assertJsonPath('data.0.name', 'Blue band')->assertJsonPath('data.1.templates_count', 0);
    $this->getJson('/api/v1/library-images?filter[search]=logo')->assertJsonCount(1, 'data');

    $this->putJson("/api/v1/library-images/{$image->public_id}", ['name' => 'Old logo'])->assertOk()->assertJsonPath('data.name', 'Old logo');

    $url = $response->json('data.url');
    $this->get($url)->assertOk()->assertHeader('Content-Type', 'image/jpeg');
    $this->get(strtok($url, '?').'?v='.substr(sha1($image->path), 0, 12))->assertForbidden();

    $this->post('/api/v1/library-images', ['file' => UploadedFile::fake()->createWithContent('logo.png', 'not an image')], ['Accept' => 'application/json'])
        ->assertJsonValidationErrors('file');
});

it('keeps images that templates use and replaces them everywhere with new template versions', function () {
    Sanctum::actingAs($this->owner);
    [$old, $new] = app(TenantContext::class)->run($this->tenant, fn () => TenantImage::factory()->count(2)->create(['tenant_id' => $this->tenant->id]));
    Storage::disk(config('cardholder_files.disk'))->put($old->path, 'old');
    $staff = templateUsingLibraryImage($this, $old, 'Staff card');
    $visitor = templateUsingLibraryImage($this, $old, 'Visitor card');

    $this->getJson('/api/v1/library-images')->assertJsonPath('data.1.templates_count', 2);
    $this->deleteJson("/api/v1/library-images/{$old->public_id}")
        ->assertUnprocessable()
        ->assertJsonPath('message', __('images.in_use', ['templates' => 'Staff card, Visitor card']));

    $this->postJson("/api/v1/library-images/{$old->public_id}/replace", ['with' => $old->public_id])->assertUnprocessable();
    $this->postJson("/api/v1/library-images/{$old->public_id}/replace", ['with' => $new->public_id])->assertOk()->assertJsonPath('data.templates', 2);

    expect($staff->fresh()->version)->toBe(4)
        ->and($visitor->fresh()->version)->toBe(4)
        ->and($staff->elements()->value('library_image_id'))->toBe($new->id);

    $this->deleteJson("/api/v1/library-images/{$old->public_id}")->assertNoContent();
    Storage::disk(config('cardholder_files.disk'))->assertMissing($old->path);
});

it('lets template users pick images and keeps the library per organization', function () {
    $image = app(TenantContext::class)->run($this->tenant, fn () => TenantImage::factory()->create(['tenant_id' => $this->tenant->id]));

    Sanctum::actingAs(tenantUser($this->tenant, 'Operator'));
    $this->getJson('/api/v1/library-images')->assertOk()->assertJsonCount(1, 'data');
    $this->post('/api/v1/library-images', ['file' => UploadedFile::fake()->image('a.png')], ['Accept' => 'application/json'])->assertForbidden();
    $this->putJson("/api/v1/library-images/{$image->public_id}", ['name' => 'x'])->assertForbidden();
    $this->deleteJson("/api/v1/library-images/{$image->public_id}")->assertForbidden();

    Sanctum::actingAs(tenantUser($this->tenant, 'Viewer'));
    $this->getJson('/api/v1/library-images')->assertForbidden();

    [$other, $otherOwner] = tenantWithOwner('other', 'owner@other.test');
    $foreign = app(TenantContext::class)->run($other, fn () => TenantImage::factory()->create(['tenant_id' => $other->id]));
    Sanctum::actingAs($otherOwner);
    $this->getJson('/api/v1/library-images')->assertJsonCount(1, 'data');
    $this->deleteJson("/api/v1/library-images/{$image->public_id}")->assertNotFound();

    Sanctum::actingAs($this->owner);
    $this->postJson("/api/v1/library-images/{$image->public_id}/replace", ['with' => $foreign->public_id])->assertNotFound();
});
