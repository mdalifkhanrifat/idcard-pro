<?php

use App\Models\CardholderFile;
use App\Models\FamilyMember;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    Storage::fake(config('cardholder_files.disk'));
    [$this->tenant, $this->owner] = tenantWithOwner();
    $this->cardholder = cardholder($this->tenant);
    $this->base = "/api/v1/cardholders/{$this->cardholder->public_id}/files";
});

function pdfUpload(string $name = 'service-record.pdf'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n1 0 obj << >> endobj\ntrailer << >>\n%%EOF");
}

it('uploads a photo, re-encodes it and stores it privately', function () {
    Sanctum::actingAs($this->owner);

    $response = $this->post($this->base, ['collection' => 'photo', 'file' => UploadedFile::fake()->image('Me.PNG', 2400, 1600)], ['Accept' => 'application/json'])
        ->assertCreated()
        ->assertJsonPath('data.collection', 'photo')
        ->assertJsonPath('data.mime_type', 'image/jpeg')
        ->assertJsonPath('data.width', 1200)
        ->assertJsonPath('data.height', 800)
        ->assertJsonPath('data.is_current', true)
        ->assertJsonPath('data.original_name', 'Me.PNG')
        ->assertJsonPath('data.uploaded_by.name', 'Owner');

    $file = CardholderFile::where('public_id', $response->json('data.id'))->firstOrFail();

    expect($file->path)->toStartWith("tenants/{$this->tenant->public_id}/cardholders/{$this->cardholder->public_id}/photo/")
        ->and($file->path)->toEndWith('.jpg')
        ->and($file->path)->not->toContain('Me');
    Storage::disk(config('cardholder_files.disk'))->assertExists($file->path);
});

it('keeps one current photo and archives the previous one', function () {
    Sanctum::actingAs($this->owner);

    $first = $this->post($this->base, ['collection' => 'photo', 'file' => UploadedFile::fake()->image('a.jpg')], ['Accept' => 'application/json'])->json('data.id');
    $second = $this->post($this->base, ['collection' => 'photo', 'file' => UploadedFile::fake()->image('b.jpg')], ['Accept' => 'application/json'])->json('data.id');

    $this->getJson("{$this->base}?collection=photo")->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $second);
    $this->getJson("{$this->base}?collection=photo&include_replaced=1")
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.1.id', $first)
        ->assertJsonPath('data.1.is_current', false);

    expect($this->cardholder->fresh()->photo->public_id)->toBe($second);
});

it('keeps several documents with titles', function () {
    Sanctum::actingAs($this->owner);

    $this->post($this->base, ['collection' => 'document', 'title' => 'Service record', 'file' => pdfUpload()], ['Accept' => 'application/json'])
        ->assertCreated()
        ->assertJsonPath('data.mime_type', 'application/pdf')
        ->assertJsonPath('data.title', 'Service record');
    $this->post($this->base, ['collection' => 'document', 'file' => UploadedFile::fake()->image('scan.jpg')], ['Accept' => 'application/json'])->assertCreated();

    $this->getJson("{$this->base}?collection=document")->assertJsonCount(2, 'data');
});

it('rejects wrong file types, oversized files and unknown collections', function () {
    Sanctum::actingAs($this->owner);
    $post = fn (array $data) => $this->post($this->base, $data, ['Accept' => 'application/json']);

    $post(['collection' => 'photo', 'file' => pdfUpload('photo.jpg')])->assertJsonValidationErrors('file');
    $post(['collection' => 'signature', 'file' => UploadedFile::fake()->create('sign.png', 3000, 'image/png')])->assertJsonValidationErrors('file');
    $post(['collection' => 'document', 'file' => UploadedFile::fake()->createWithContent('run.php', '<?php echo 1;')])->assertJsonValidationErrors('file');
    $post(['collection' => 'fingerprint', 'file' => UploadedFile::fake()->image('f.png')])->assertJsonValidationErrors('collection');
});

it('serves files only through valid signed links', function () {
    Sanctum::actingAs($this->owner);
    $url = $this->post($this->base, ['collection' => 'document', 'file' => pdfUpload()], ['Accept' => 'application/json'])->json('data.url');

    $this->app['auth']->forgetGuards();
    $this->get($url)
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf')
        ->assertHeader('X-Content-Type-Options', 'nosniff');

    $this->get($url.'x')->assertForbidden();
    $this->travel(config('cardholder_files.url_ttl') + 1)->minutes();
    $this->get($url)->assertForbidden();
});

it('deletes a file from storage', function () {
    Sanctum::actingAs($this->owner);
    $id = $this->post($this->base, ['collection' => 'signature', 'file' => UploadedFile::fake()->image('s.png')], ['Accept' => 'application/json'])->json('data.id');
    $path = CardholderFile::where('public_id', $id)->value('path');

    $this->deleteJson("{$this->base}/{$id}")->assertNoContent();

    expect(CardholderFile::where('public_id', $id)->exists())->toBeFalse();
    Storage::disk(config('cardholder_files.disk'))->assertMissing($path);
});

it('does not reach files through another cardholder or organization', function () {
    Sanctum::actingAs($this->owner);
    $id = $this->post($this->base, ['collection' => 'document', 'file' => pdfUpload()], ['Accept' => 'application/json'])->json('data.id');
    $other = cardholder($this->tenant);

    $this->deleteJson("/api/v1/cardholders/{$other->public_id}/files/{$id}")->assertNotFound();

    [$otherTenant] = tenantWithOwner('beta', 'b@example.com');
    $foreign = cardholder($otherTenant);
    $this->getJson("/api/v1/cardholders/{$foreign->public_id}/files")->assertNotFound();
    $this->post("/api/v1/cardholders/{$foreign->public_id}/files", ['collection' => 'document', 'file' => pdfUpload()], ['Accept' => 'application/json'])->assertNotFound();
});

it('enforces permissions', function () {
    Sanctum::actingAs(tenantUser($this->tenant, 'Viewer'));
    $this->getJson($this->base)->assertOk();
    $this->post($this->base, ['collection' => 'document', 'file' => pdfUpload()], ['Accept' => 'application/json'])->assertForbidden();

    Sanctum::actingAs(tenantUser($this->tenant, 'Operator'));
    $this->post($this->base, ['collection' => 'document', 'file' => pdfUpload()], ['Accept' => 'application/json'])->assertCreated();
});

it('shows the current photo and signature on the cardholder', function () {
    Sanctum::actingAs($this->owner);
    $photo = $this->post($this->base, ['collection' => 'photo', 'file' => UploadedFile::fake()->image('p.jpg', 300, 400)], ['Accept' => 'application/json'])->json('data.id');

    $this->getJson("/api/v1/cardholders/{$this->cardholder->public_id}")
        ->assertJsonPath('data.photo.id', $photo)
        ->assertJsonPath('data.photo.width', 300)
        ->assertJsonPath('data.signature', null);

    $this->getJson('/api/v1/cardholders')->assertJsonPath('data.0.photo.id', $photo);
});

it('removes stored files when the cardholder is deleted', function () {
    Sanctum::actingAs($this->owner);
    $this->post($this->base, ['collection' => 'photo', 'file' => UploadedFile::fake()->image('a.jpg')], ['Accept' => 'application/json']);
    $this->post($this->base, ['collection' => 'photo', 'file' => UploadedFile::fake()->image('b.jpg')], ['Accept' => 'application/json']);
    $this->post($this->base, ['collection' => 'document', 'file' => pdfUpload()], ['Accept' => 'application/json']);
    $paths = CardholderFile::pluck('path');

    $this->deleteJson("/api/v1/cardholders/{$this->cardholder->public_id}")->assertNoContent();

    expect($paths)->toHaveCount(3)->and(CardholderFile::count())->toBe(0);
    $paths->each(fn (string $path) => Storage::disk(config('cardholder_files.disk'))->assertMissing($path));
});

it('keeps a family member\'s own photo and signature apart from the cardholder\'s', function () {
    Sanctum::actingAs($this->owner);
    [$spouse, $child] = app(TenantContext::class)->run($this->tenant, fn () => FamilyMember::factory()->count(2)->create(['tenant_id' => $this->tenant->id, 'cardholder_id' => $this->cardholder->id])->all());
    $upload = fn (array $data) => $this->post($this->base, [...$data, 'file' => UploadedFile::fake()->image('p.jpg')], ['Accept' => 'application/json']);

    $own = $upload(['collection' => 'photo'])->assertCreated()->assertJsonPath('data.family_member', null)->json('data.id');
    $first = $upload(['collection' => 'photo', 'family_member' => $spouse->public_id])->json('data.id');
    $second = $upload(['collection' => 'photo', 'family_member' => $spouse->public_id])
        ->assertCreated()
        ->assertJsonPath('data.family_member.id', $spouse->public_id)
        ->json('data.id');
    $upload(['collection' => 'signature', 'family_member' => $child->public_id])->assertCreated();

    // One current photo per person: the spouse's new photo archives only the spouse's previous one
    expect($this->cardholder->fresh()->photo->public_id)->toBe($own)
        ->and($spouse->fresh()->photo->public_id)->toBe($second)
        ->and($child->fresh()->photo)->toBeNull()
        ->and($child->fresh()->signature)->not->toBeNull()
        ->and(CardholderFile::where('public_id', $second)->value('path'))->toContain("/family/{$spouse->public_id}/photo/");

    $this->getJson($this->base)->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $own);
    $this->getJson("{$this->base}?family_member={$spouse->public_id}&include_replaced=1")
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.id', $second)
        ->assertJsonPath('data.1.id', $first);

    $this->getJson("/api/v1/cardholders/{$this->cardholder->public_id}/family-members")
        ->assertJsonPath('data.0.photo.id', $second)
        ->assertJsonPath('data.0.signature', null)
        ->assertJsonPath('data.1.signature.id', $child->fresh()->signature->public_id);
});

it('allows only photos and signatures for family members of the same cardholder', function () {
    Sanctum::actingAs($this->owner);
    $other = cardholder($this->tenant);
    [$member, $stranger] = app(TenantContext::class)->run($this->tenant, fn () => [
        FamilyMember::factory()->create(['tenant_id' => $this->tenant->id, 'cardholder_id' => $this->cardholder->id]),
        FamilyMember::factory()->create(['tenant_id' => $this->tenant->id, 'cardholder_id' => $other->id]),
    ]);

    $this->post($this->base, ['collection' => 'document', 'family_member' => $member->public_id, 'file' => pdfUpload()], ['Accept' => 'application/json'])
        ->assertJsonValidationErrors('collection');
    $this->post($this->base, ['collection' => 'photo', 'family_member' => $stranger->public_id, 'file' => UploadedFile::fake()->image('p.jpg')], ['Accept' => 'application/json'])
        ->assertJsonValidationErrors('family_member');
    $this->getJson("{$this->base}?family_member={$stranger->public_id}")->assertJsonValidationErrors('family_member');

    expect(CardholderFile::count())->toBe(0);
});

it('removes a family member\'s stored files with the member', function () {
    Sanctum::actingAs($this->owner);
    $member = app(TenantContext::class)->run($this->tenant, fn () => FamilyMember::factory()->create(['tenant_id' => $this->tenant->id, 'cardholder_id' => $this->cardholder->id]));
    $this->post($this->base, ['collection' => 'photo', 'file' => UploadedFile::fake()->image('own.jpg')], ['Accept' => 'application/json']);
    $this->post($this->base, ['collection' => 'photo', 'family_member' => $member->public_id, 'file' => UploadedFile::fake()->image('m.jpg')], ['Accept' => 'application/json']);
    $memberPath = $member->fresh()->photo->path;

    $this->deleteJson("/api/v1/cardholders/{$this->cardholder->public_id}/family-members/{$member->public_id}")->assertNoContent();

    Storage::disk(config('cardholder_files.disk'))->assertMissing($memberPath);
    Storage::disk(config('cardholder_files.disk'))->assertExists($this->cardholder->fresh()->photo->path);
    expect(CardholderFile::count())->toBe(1);
});
