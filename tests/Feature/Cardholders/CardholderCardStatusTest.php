<?php

use App\Models\FamilyMember;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    Storage::fake(config('cardholder_files.disk'));
    [$this->tenant, $this->owner] = tenantWithOwner();
    $this->template = cardTemplate($this->tenant, ['code' => 'EMP']);
    $family = cardTemplate($this->tenant, ['code' => 'FAM', 'audience' => 'family_member']);
    Sanctum::actingAs($this->owner);
    $issue = fn ($holder, array $extra = []) => $this->postJson("/api/v1/cardholders/{$holder->public_id}/cards", ['template' => $this->template->public_id, 'reason' => 'new', ...$extra])->assertCreated()->json('data');

    $this->none = cardholder($this->tenant, ['full_name' => 'No Card']);
    $this->pending = cardholder($this->tenant, ['full_name' => 'Not Printed']);
    $this->printed = cardholder($this->tenant, ['full_name' => 'Printed Twice']);
    $this->familyOnly = cardholder($this->tenant, ['full_name' => 'Family Only']);

    $issue($this->pending);
    $printedCard = $issue($this->printed);
    $this->postJson("/api/v1/cards/{$printedCard['id']}/prints", ['copies' => 2])->assertCreated();
    // Own card revoked and only a family member's card: counts as no card of their own
    $revoked = $issue($this->familyOnly);
    $this->postJson("/api/v1/cards/{$revoked['id']}/revoke", ['note' => 'Lost'])->assertOk();
    $member = app(TenantContext::class)->run($this->tenant, fn () => FamilyMember::factory()->create(['tenant_id' => $this->tenant->id, 'cardholder_id' => $this->familyOnly->id]));
    $this->postJson("/api/v1/cardholders/{$this->familyOnly->public_id}/cards", ['template' => $family->public_id, 'family_member' => $member->public_id, 'reason' => 'new'])->assertCreated();
    $this->printedCard = $printedCard;
});

it('shows each cardholder\'s own current card and whether it was printed (legacy PENDING / PRINTED)', function () {
    $rows = collect($this->getJson('/api/v1/cardholders?per_page=50')->assertOk()->json('data'))->keyBy('full_name');

    expect($rows['No Card']['current_card'])->toBeNull()
        ->and($rows['Family Only']['current_card'])->toBeNull()
        ->and($rows['Not Printed']['current_card'])->toMatchArray(['print_count' => 0, 'last_printed_at' => null])
        ->and($rows['Printed Twice']['current_card'])->toMatchArray(['id' => $this->printedCard['id'], 'number' => $this->printedCard['number'], 'print_count' => 2])
        ->and($rows['Printed Twice']['current_card']['last_printed_at'])->not->toBeNull();
});

it('filters cardholders by their card: none, never printed or printed', function () {
    $names = fn (string $state) => collect($this->getJson("/api/v1/cardholders?filter[card]={$state}")->assertOk()->json('data'))->pluck('full_name')->sort()->values()->all();

    expect($names('none'))->toBe(['Family Only', 'No Card'])
        ->and($names('pending'))->toBe(['Not Printed'])
        ->and($names('printed'))->toBe(['Printed Twice']);
    $this->getJson('/api/v1/cardholders?filter[card]=lost')->assertJsonValidationErrors('filter.card');
});

it('loads the cards of the whole page at once', function () {
    $count = function () {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->getJson('/api/v1/cardholders?per_page=50')->assertOk();

        return count(DB::getQueryLog());
    };
    $before = $count();
    foreach (range(1, 5) as $i) {
        $holder = cardholder($this->tenant);
        $this->postJson("/api/v1/cardholders/{$holder->public_id}/cards", ['template' => $this->template->public_id, 'reason' => 'new'])->assertCreated();
    }

    expect($count())->toBe($before);
});
