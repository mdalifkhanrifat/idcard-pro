<?php

use App\Models\Card;
use App\Models\FamilyMember;
use App\Models\Signatory;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    Storage::fake(config('cardholder_files.disk'));
    [$this->tenant, $this->owner] = tenantWithOwner();
    $this->holder = cardholder($this->tenant, ['full_name' => 'Jane Doe']);
    $this->template = cardTemplate($this->tenant, ['code' => 'EMP', 'validity_months' => 24]);
    $this->url = "/api/v1/cardholders/{$this->holder->public_id}/cards";
    $this->year = now()->format('y');
});

it('issues a card with a new number, the design snapshot and the expiry from the template', function () {
    Sanctum::actingAs($this->owner);
    $signatory = app(TenantContext::class)->run($this->tenant, fn () => Signatory::factory()->create(['tenant_id' => $this->tenant->id, 'authority_type_id' => $this->template->authority_type_id ?? masterItem($this->tenant, ['type' => 'authority_type', 'name' => 'HQ'])->id]));
    $this->template->update(['authority_type_id' => $signatory->authority_type_id]);

    $this->getJson("{$this->url}/next-number?template={$this->template->public_id}")
        ->assertOk()
        ->assertJsonPath('data.number', "EMP/{$this->year}000001")
        ->assertJsonPath('data.kept', false);

    $this->postJson($this->url, ['template' => $this->template->public_id, 'reason' => 'new', 'issue_date' => '2026-03-01', 'rf_card_no' => 'RF-1'])
        ->assertCreated()
        ->assertJsonPath('data.number', 'EMP/26000001')
        ->assertJsonPath('data.status', 'active')
        ->assertJsonPath('data.expiry_date', '2028-02-29')
        ->assertJsonPath('data.template.code', 'EMP')
        ->assertJsonPath('data.issued_by', $this->owner->name);

    $card = Card::query()->sole();
    expect($card->layout['elements'][0])->toMatchArray(['type' => 'field', 'field' => 'full_name', 'x' => 5.0])
        ->and($card->layout['size_mm'])->toEqual(['width' => 85.6, 'height' => 54])
        ->and($card->template_version)->toBe($this->template->version)
        ->and($card->signatory_id)->toBe($signatory->id)
        ->and($card->active_holder_key)->toBe("c{$this->holder->id}");
});

it('keeps the number on reissue by default and replaces the previous card', function () {
    Sanctum::actingAs($this->owner);
    $first = $this->postJson($this->url, ['template' => $this->template->public_id, 'reason' => 'new'])->json('data');

    $this->getJson("{$this->url}/next-number?template={$this->template->public_id}")->assertJsonPath('data.kept', true);
    $second = $this->postJson($this->url, ['template' => $this->template->public_id, 'reason' => 'lost', 'notes' => 'Lost on the bus'])
        ->assertCreated()
        ->json('data');

    expect($second['number'])->toBe($first['number']);
    $this->getJson($this->url)
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.status', 'active')
        ->assertJsonPath('data.1.status', 'replaced')
        ->assertJsonPath('data.1.replaced_by.id', $second['id']);

    // Another template code: a number from that code's sequence
    $visitor = cardTemplate($this->tenant, ['code' => 'VIS']);
    $this->postJson($this->url, ['template' => $visitor->public_id, 'reason' => 'renewal'])->assertJsonPath('data.number', "VIS/{$this->year}000001");
    expect(Card::query()->where('status', 'active')->count())->toBe(1);
});

it('allows the reason "new" for each holder\'s first card only (legacy)', function () {
    Sanctum::actingAs($this->owner);
    $member = app(TenantContext::class)->run($this->tenant, fn () => FamilyMember::factory()->create(['tenant_id' => $this->tenant->id, 'cardholder_id' => $this->holder->id]));
    $family = cardTemplate($this->tenant, ['code' => 'FAM', 'audience' => 'family_member']);

    $this->postJson($this->url, ['template' => $this->template->public_id, 'reason' => 'new'])->assertCreated();
    $this->postJson($this->url, ['template' => $this->template->public_id, 'reason' => 'new'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['reason' => __('cards.issue.new_not_first')]);
    $this->postJson($this->url, ['template' => $this->template->public_id, 'reason' => 'renewal'])->assertCreated();

    // A family member's first card is still new
    $this->postJson($this->url, ['template' => $family->public_id, 'family_member' => $member->public_id, 'reason' => 'new'])->assertCreated();
});

it('gives a new number on every issue when the organization chooses so', function () {
    Sanctum::actingAs($this->owner);
    $this->tenant->update(['settings' => [...($this->tenant->settings ?? []), 'card_number_on_reissue' => 'new']]);

    $this->postJson($this->url, ['template' => $this->template->public_id, 'reason' => 'new'])->assertJsonPath('data.number', "EMP/{$this->year}000001");
    $this->postJson($this->url, ['template' => $this->template->public_id, 'reason' => 'damaged'])->assertJsonPath('data.number', "EMP/{$this->year}000002");
});

it('issues family cards from family templates only, with their own history', function () {
    Sanctum::actingAs($this->owner);
    $member = app(TenantContext::class)->run($this->tenant, fn () => FamilyMember::factory()->create(['tenant_id' => $this->tenant->id, 'cardholder_id' => $this->holder->id]));
    $family = cardTemplate($this->tenant, ['code' => 'FAM', 'audience' => 'family_member']);

    $this->postJson($this->url, ['template' => $family->public_id, 'reason' => 'new'])->assertJsonValidationErrors('family_member');
    $this->postJson($this->url, ['template' => $this->template->public_id, 'family_member' => $member->public_id, 'reason' => 'new'])->assertJsonValidationErrors('template');

    $this->postJson($this->url, ['template' => $this->template->public_id, 'reason' => 'new'])->assertCreated();
    $this->postJson($this->url, ['template' => $family->public_id, 'family_member' => $member->public_id, 'reason' => 'new'])
        ->assertCreated()
        ->assertJsonPath('data.family_member.id', $member->public_id);

    // The cardholder's own card and the family card are both active
    expect(Card::query()->where('status', 'active')->count())->toBe(2);

    $otherHolder = cardholder($this->tenant);
    $this->postJson("/api/v1/cardholders/{$otherHolder->public_id}/cards", ['template' => $family->public_id, 'family_member' => $member->public_id, 'reason' => 'new'])
        ->assertJsonValidationErrors('family_member');
});

it('prints the family member\'s own photo and signature on a family card', function () {
    Sanctum::actingAs($this->owner);
    $member = app(TenantContext::class)->run($this->tenant, fn () => FamilyMember::factory()->create(['tenant_id' => $this->tenant->id, 'cardholder_id' => $this->holder->id]));
    $family = cardTemplate($this->tenant, ['code' => 'FAM', 'audience' => 'family_member']);
    $files = "/api/v1/cardholders/{$this->holder->public_id}/files";
    $upload = fn (array $data) => $this->post($files, [...$data, 'file' => UploadedFile::fake()->image('i.png', 300, 300)], ['Accept' => 'application/json'])->assertCreated()->json('data.id');
    $upload(['collection' => 'photo']);
    $upload(['collection' => 'signature']);
    $issue = fn (string $reason = 'renewal') => Card::where('public_id', $this->postJson($this->url, ['template' => $family->public_id, 'family_member' => $member->public_id, 'reason' => $reason])->assertCreated()->json('data.id'))->firstOrFail();

    // Without their own photo the card prints none, not the cardholder's
    expect($issue('new')->only(['photo_file_id', 'signature_file_id']))->toBe(['photo_file_id' => null, 'signature_file_id' => null]);

    $photo = $upload(['collection' => 'photo', 'family_member' => $member->public_id]);
    $card = $issue();
    expect($card->photo->public_id)->toBe($photo)
        ->and($card->signature_file_id)->toBeNull();
    $this->getJson("/api/v1/cards/{$card->public_id}")->assertJsonPath('meta.values.images.photo', fn (string $url) => str_contains($url, $photo));

    // The printed photo stays protected
    $this->deleteJson("{$files}/{$photo}")->assertUnprocessable();
});

it('checks reasons, notes, documents, dates and RF numbers', function () {
    Sanctum::actingAs($this->owner);
    $inactive = cardTemplate($this->tenant, ['is_active' => false]);

    $this->postJson($this->url, ['template' => $inactive->public_id, 'reason' => 'new'])->assertJsonValidationErrors('template');
    $this->postJson($this->url, ['template' => $this->template->public_id, 'reason' => 'stolen'])->assertJsonValidationErrors('reason');
    $this->postJson($this->url, ['template' => $this->template->public_id, 'reason' => 'promotion'])->assertJsonValidationErrors(['notes' => __('cards.issue.note_required')]);
    $this->post($this->url, ['template' => $this->template->public_id, 'reason' => 'new', 'document' => UploadedFile::fake()->create('proof.pdf', 20, 'application/pdf')], ['Accept' => 'application/json'])
        ->assertJsonValidationErrors('document');
    $this->postJson($this->url, ['template' => $this->template->public_id, 'reason' => 'new', 'issue_date' => '2026-05-01', 'expiry_date' => '2026-04-01'])
        ->assertJsonValidationErrors('expiry_date');

    // A proof document is stored with the cardholder's documents
    $this->post($this->url, ['template' => $this->template->public_id, 'reason' => 'lost', 'notes' => 'Police report attached', 'rf_card_no' => 'RF-9', 'document' => UploadedFile::fake()->create('report.pdf', 20, 'application/pdf')], ['Accept' => 'application/json'])
        ->assertCreated()
        ->assertJsonPath('data.document.name', 'report.pdf');
    expect($this->holder->files()->where('collection', 'document')->count())->toBe(1);

    // The RF card is on this holder's active card, so nobody else may get it
    $other = cardholder($this->tenant);
    $this->postJson("/api/v1/cardholders/{$other->public_id}/cards", ['template' => $this->template->public_id, 'reason' => 'new', 'rf_card_no' => 'RF-9'])
        ->assertJsonValidationErrors(['rf_card_no' => __('cards.issue.rf_in_use')]);
    // Keeping it on the holder's own reissue is fine
    $this->postJson($this->url, ['template' => $this->template->public_id, 'reason' => 'renewal', 'rf_card_no' => 'RF-9'])->assertCreated();
});

it('lets operators issue, viewers only look, and keeps cards per organization', function () {
    Sanctum::actingAs(tenantUser($this->tenant, 'Operator'));
    $this->postJson($this->url, ['template' => $this->template->public_id, 'reason' => 'new'])->assertCreated();

    Sanctum::actingAs(tenantUser($this->tenant, 'Viewer'));
    $this->getJson($this->url)->assertOk()->assertJsonCount(1, 'data');
    $this->postJson($this->url, ['template' => $this->template->public_id, 'reason' => 'new'])->assertForbidden();

    [$other, $otherOwner] = tenantWithOwner('other', 'owner@other.test');
    Sanctum::actingAs($otherOwner);
    $this->getJson($this->url)->assertNotFound();
    $foreignHolder = cardholder($other);
    $this->postJson("/api/v1/cardholders/{$foreignHolder->public_id}/cards", ['template' => $this->template->public_id, 'reason' => 'new'])
        ->assertJsonValidationErrors('template');
});
