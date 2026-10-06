<?php

use App\Models\Card;
use App\Models\FamilyMember;
use App\Models\Role;
use App\Support\Permissions\PermissionRegistry;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    Storage::fake(config('cardholder_files.disk'));
    [$this->tenant, $this->owner] = tenantWithOwner();
    $this->department = masterItem($this->tenant, ['type' => 'department', 'name' => 'Operations']);
    $this->template = cardTemplate($this->tenant, ['code' => 'EMP', 'name' => 'Staff card']);
    $this->family = cardTemplate($this->tenant, ['code' => 'FAM', 'audience' => 'family_member']);
    Sanctum::actingAs($this->owner);

    $issue = fn ($holder, array $extra = []) => $this->postJson("/api/v1/cardholders/{$holder->public_id}/cards", ['template' => $this->template->public_id, 'reason' => 'new', ...$extra])->assertCreated()->json('data');
    $this->jane = cardholder($this->tenant, ['full_name' => 'Jane Doe', 'employee_no' => 'E-1', 'department_id' => $this->department->id]);
    $this->john = cardholder($this->tenant, ['full_name' => 'John Roe', 'employee_no' => 'E-2']);
    // Jane: expired card replaced by a valid one; John: expiring soon, then a family card
    $issue($this->jane, ['issue_date' => '2024-01-01', 'expiry_date' => '2025-01-01']);
    $issue($this->jane, ['reason' => 'renewal', 'issue_date' => today()->toDateString(), 'expiry_date' => today()->addYears(2)->toDateString()]);
    $this->expiring = $issue($this->john, ['issue_date' => '2024-05-01', 'expiry_date' => today()->addDays(10)->toDateString(), 'rf_card_no' => 'RF-55']);
    $member = app(TenantContext::class)->run($this->tenant, fn () => FamilyMember::factory()->create(['tenant_id' => $this->tenant->id, 'cardholder_id' => $this->john->id, 'name' => 'Baby Roe']));
    $this->postJson("/api/v1/cardholders/{$this->john->public_id}/cards", ['template' => $this->family->public_id, 'family_member' => $member->public_id, 'reason' => 'new', 'expiry_date' => today()->subDay()->toDateString(), 'issue_date' => '2024-01-01'])->assertCreated();
});

it('lists issued cards with search, status, holder and date filters', function () {
    $this->getJson('/api/v1/cards')->assertOk()->assertJsonCount(4, 'data')->assertJsonPath('meta.total', 4);
    $this->getJson('/api/v1/cards?filter[search]=jane')->assertJsonCount(2, 'data');
    $this->getJson('/api/v1/cards?filter[search]=RF-55')->assertJsonCount(1, 'data')->assertJsonPath('data.0.cardholder.full_name', 'John Roe');
    $this->getJson('/api/v1/cards?filter[search]=Baby')->assertJsonCount(1, 'data')->assertJsonPath('data.0.family_member.name', 'Baby Roe');
    $this->getJson('/api/v1/cards?filter[status]=replaced')->assertJsonCount(1, 'data');
    $this->getJson('/api/v1/cards?filter[reason]=renewal')->assertJsonCount(1, 'data');
    $this->getJson('/api/v1/cards?filter[holder]=family')->assertJsonCount(1, 'data');
    $this->getJson("/api/v1/cards?filter[template]={$this->template->public_id}&filter[holder]=cardholder")->assertJsonCount(3, 'data');
    $this->getJson('/api/v1/cards?filter[issued_from]=2024-01-01&filter[issued_to]=2024-12-31')->assertJsonCount(3, 'data');
    $this->getJson("/api/v1/cards?filter[department]={$this->department->public_id}")->assertJsonCount(2, 'data')->assertJsonPath('data.0.cardholder.department', 'Operations');
    $this->getJson('/api/v1/cards?sort=issue_date&per_page=1')->assertJsonCount(1, 'data')->assertJsonPath('meta.last_page', 4);
    $this->getJson('/api/v1/cards?filter[status]=lost')->assertJsonValidationErrors('filter.status');
});

it('finds expired and soon expiring active cards (legacy expired card report)', function () {
    // The replaced 2025 card is not counted: only active cards expire
    $this->getJson('/api/v1/cards?filter[validity]=expired')->assertJsonCount(1, 'data')->assertJsonPath('data.0.family_member.name', 'Baby Roe');
    $this->getJson('/api/v1/cards?filter[validity]=expiring&filter[expiring_days]=30')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $this->expiring['id']);
    $this->getJson('/api/v1/cards?filter[validity]=expiring&filter[expiring_days]=5')->assertJsonCount(0, 'data');
    $this->getJson('/api/v1/cards?filter[validity]=valid')->assertJsonCount(2, 'data');

    $this->getJson('/api/v1/cards/summary')->assertOk()->assertJson(['data' => ['active' => 3, 'expired' => 1, 'expiring' => 1, 'revoked' => 0]]);
    $this->getJson('/api/v1/cards/summary?expiring_days=5')->assertJsonPath('data.expiring', 0);
});

it('keeps the list per organization and needs cards.view', function () {
    [, $otherOwner] = tenantWithOwner('other', 'owner@other.test');
    Sanctum::actingAs($otherOwner);
    $this->getJson('/api/v1/cards')->assertJsonCount(0, 'data');
    $this->getJson('/api/v1/cards/summary')->assertJsonPath('data.active', 0);

    $noCards = tenantUser($this->tenant, 'Viewer');
    app(TenantContext::class)->run($this->tenant, fn () => Role::findByName('Viewer', PermissionRegistry::GUARD)->revokePermissionTo('cards.view'));
    Sanctum::actingAs($noCards->fresh());
    $this->getJson('/api/v1/cards')->assertForbidden();
    expect(Card::count())->toBe(4);
});

it('offers the reasons and statuses from the configuration', function () {
    $this->getJson('/api/v1/cards/options')
        ->assertOk()
        ->assertJsonPath('data.reasons.0', ['key' => 'new', 'note' => false, 'document' => false])
        ->assertJsonPath('data.reasons.3', ['key' => 'lost', 'note' => true, 'document' => true])
        ->assertJsonPath('data.statuses', ['active', 'replaced', 'revoked'])
        ->assertJsonPath('data.print', ['methods' => ['browser', 'pdf'], 'max_copies' => 10, 'max_batch' => 200, 'pdf' => true]);

    config(['pdf.driver' => null]);
    $this->getJson('/api/v1/cards/options')->assertJsonPath('data.print.pdf', false);
});
