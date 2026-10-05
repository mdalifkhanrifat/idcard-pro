<?php

use App\Models\FamilyMember;
use App\Models\Signatory;
use App\Models\TenantFont;
use App\Models\TenantImage;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    Storage::fake(config('cardholder_files.disk'));
    [$this->tenant, $this->owner] = tenantWithOwner();
    $this->holder = cardholder($this->tenant);
    $this->template = cardTemplate($this->tenant, ['code' => 'EMP']);
    $this->issue = fn (array $extra = []) => $this->postJson("/api/v1/cardholders/{$this->holder->public_id}/cards", ['template' => $this->template->public_id, 'reason' => 'new', ...$extra]);
    Sanctum::actingAs($this->owner);
});

it('updates the RF number, notes and expiry of a card', function () {
    $card = ($this->issue)(['issue_date' => '2026-01-01'])->json('data');

    $this->patchJson("/api/v1/cards/{$card['id']}", ['rf_card_no' => 'RF-77', 'notes' => 'Collected', 'expiry_date' => '2027-12-31'])
        ->assertOk()
        ->assertJsonPath('data.rf_card_no', 'RF-77')
        ->assertJsonPath('data.notes', 'Collected')
        ->assertJsonPath('data.expiry_date', '2027-12-31');
    $this->patchJson("/api/v1/cards/{$card['id']}", ['expiry_date' => '2025-01-01'])->assertJsonValidationErrors('expiry_date');

    $other = cardholder($this->tenant);
    $otherCard = $this->postJson("/api/v1/cardholders/{$other->public_id}/cards", ['template' => $this->template->public_id, 'reason' => 'new'])->json('data');
    $this->patchJson("/api/v1/cards/{$otherCard['id']}", ['rf_card_no' => 'RF-77'])->assertJsonValidationErrors('rf_card_no');
});

it('revokes active cards only, after which the holder can get a new one', function () {
    $card = ($this->issue)()->json('data');

    $this->postJson("/api/v1/cards/{$card['id']}/revoke")->assertJsonValidationErrors('note');
    $this->postJson("/api/v1/cards/{$card['id']}/revoke", ['note' => 'Reported lost'])
        ->assertOk()
        ->assertJsonPath('data.status', 'revoked')
        ->assertJsonPath('data.status_note', 'Reported lost');
    $this->postJson("/api/v1/cards/{$card['id']}/revoke", ['note' => 'Again'])->assertUnprocessable();

    // Keeping the number is the default, so the new card shows the same number
    ($this->issue)(['reason' => 'lost', 'notes' => 'Replacement'])->assertCreated()->assertJsonPath('data.number', $card['number']);

    Sanctum::actingAs(tenantUser($this->tenant, 'Operator'));
    $this->postJson("/api/v1/cards/{$card['id']}/revoke", ['note' => 'x'])->assertForbidden();
    $this->patchJson("/api/v1/cards/{$card['id']}", ['notes' => 'x'])->assertForbidden();
});

it('keeps everything an issued card was made from', function () {
    $signatory = app(TenantContext::class)->run($this->tenant, fn () => Signatory::factory()->create(['tenant_id' => $this->tenant->id, 'authority_type_id' => masterItem($this->tenant, ['type' => 'authority_type', 'name' => 'HQ'])->id]));
    $this->template->update(['authority_type_id' => $signatory->authority_type_id]);
    $photo = $this->post("/api/v1/cardholders/{$this->holder->public_id}/files", ['collection' => 'photo', 'file' => UploadedFile::fake()->image('me.jpg', 300, 400)], ['Accept' => 'application/json'])->json('data.id');
    $member = app(TenantContext::class)->run($this->tenant, fn () => FamilyMember::factory()->create(['tenant_id' => $this->tenant->id, 'cardholder_id' => $this->holder->id]));
    $familyTemplate = cardTemplate($this->tenant, ['code' => 'FAM', 'audience' => 'family_member']);
    [$library, $font] = app(TenantContext::class)->run($this->tenant, fn () => [
        TenantImage::factory()->create(['tenant_id' => $this->tenant->id]),
        TenantFont::factory()->create(['tenant_id' => $this->tenant->id, 'family' => 'Card Lipi']),
    ]);
    $this->putJson("/api/v1/card-templates/{$this->template->public_id}/elements", ['elements' => [
        ['side' => 'front', 'type' => 'logo', 'library_image' => $library->public_id, 'x' => 1, 'y' => 1, 'width' => 10, 'height' => 10],
        ['side' => 'front', 'type' => 'field', 'field' => 'full_name', 'x' => 15, 'y' => 1, 'width' => 40, 'height' => 6, 'style' => ['font_family' => 'Card Lipi']],
    ]])->assertOk();

    ($this->issue)()->assertCreated();
    $this->postJson("/api/v1/cardholders/{$this->holder->public_id}/cards", ['template' => $familyTemplate->public_id, 'family_member' => $member->public_id, 'reason' => 'new'])->assertCreated();
    // The template stops using the image and the font; the issued card still does
    $this->putJson("/api/v1/card-templates/{$this->template->public_id}/elements", ['elements' => []])->assertOk();

    $this->deleteJson("/api/v1/cardholders/{$this->holder->public_id}")->assertUnprocessable()->assertJsonPath('message', __('cards.in_use.cardholder'));
    $this->deleteJson("/api/v1/cardholders/{$this->holder->public_id}/family-members/{$member->public_id}")->assertJsonPath('message', __('cards.in_use.family_member'));
    $this->deleteJson("/api/v1/card-templates/{$this->template->public_id}")->assertJsonPath('message', __('cards.in_use.template'));
    $this->deleteJson("/api/v1/signatories/{$signatory->public_id}")->assertJsonPath('message', __('cards.in_use.signatory'));
    $this->deleteJson("/api/v1/cardholders/{$this->holder->public_id}/files/{$photo}")->assertJsonPath('message', __('cards.in_use.file'));
    $this->deleteJson("/api/v1/library-images/{$library->public_id}")->assertJsonPath('message', __('cards.in_use.library_image'));
    $this->deleteJson("/api/v1/fonts/{$font->public_id}")->assertJsonPath('message', __('cards.in_use.font', ['family' => 'Card Lipi']));

    // A template no card came from can still be deleted
    $unused = cardTemplate($this->tenant, ['code' => 'TMP']);
    $this->deleteJson("/api/v1/card-templates/{$unused->public_id}")->assertNoContent();
});
