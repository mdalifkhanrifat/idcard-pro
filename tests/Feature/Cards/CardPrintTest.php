<?php

use App\Models\Card;
use App\Models\CardPrint;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    Storage::fake(config('cardholder_files.disk'));
    [$this->tenant, $this->owner] = tenantWithOwner();
    $this->template = cardTemplate($this->tenant, ['code' => 'EMP']);
    $this->issue = fn (array $extra = []) => $this->postJson('/api/v1/cardholders/'.cardholder($this->tenant)->public_id.'/cards', ['template' => $this->template->public_id, 'reason' => 'new', ...$extra])->json('data');
    Sanctum::actingAs($this->owner);
});

it('logs every printing of a card without issuing a new one', function () {
    $card = ($this->issue)();
    expect($card)->toMatchArray(['print_count' => 0, 'last_printed_at' => null]);

    $this->postJson("/api/v1/cards/{$card['id']}/prints")
        ->assertCreated()
        ->assertJsonPath('data.method', 'browser')
        ->assertJsonPath('data.copies', 1)
        ->assertJsonPath('data.batch', null)
        ->assertJsonPath('data.printed_by.name', 'Owner');
    // Printed again after a printer jam: same card and number, a second log entry
    $this->postJson("/api/v1/cards/{$card['id']}/prints", ['method' => 'pdf', 'copies' => 2, 'note' => 'Printer jam'])->assertCreated();

    $this->getJson("/api/v1/cards/{$card['id']}/prints")
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.note', 'Printer jam')
        ->assertJsonPath('data.0.copies', 2)
        ->assertJsonPath('data.1.method', 'browser');
    $shown = $this->getJson("/api/v1/cards/{$card['id']}")->json('data');
    expect($shown['print_count'])->toBe(3)
        ->and($shown['last_printed_at'])->not->toBeNull()
        ->and($shown['number'])->toBe($card['number'])
        ->and(Card::count())->toBe(1);
});

it('validates the method, copies and note', function () {
    $card = ($this->issue)();

    $this->postJson("/api/v1/cards/{$card['id']}/prints", ['method' => 'fax', 'copies' => 0, 'note' => str_repeat('x', 256)])
        ->assertJsonValidationErrors(['method', 'copies', 'note']);
    $this->postJson("/api/v1/cards/{$card['id']}/prints", ['copies' => config('cards.print.max_copies') + 1])->assertJsonValidationErrors('copies');
    $this->postJson("/api/v1/cards/{$card['id']}/prints", ['cards' => [$card['id']]])->assertJsonValidationErrors('cards');

    expect(CardPrint::count())->toBe(0);
});

it('prints only active cards that have not expired', function () {
    $revoked = ($this->issue)();
    $this->postJson("/api/v1/cards/{$revoked['id']}/revoke", ['note' => 'Left'])->assertOk();
    $expired = ($this->issue)(['issue_date' => '2020-01-01', 'expiry_date' => '2021-01-01']);

    // Clients read the same rule from the card itself
    expect($this->getJson("/api/v1/cards/{$revoked['id']}")->json('data.printable'))->toBeFalse()
        ->and($this->getJson("/api/v1/cards/{$expired['id']}")->json('data.printable'))->toBeFalse()
        ->and(($this->issue)()['printable'])->toBeTrue();

    $this->postJson("/api/v1/cards/{$revoked['id']}/prints")->assertUnprocessable()->assertJsonPath('message', fn (string $m) => str_contains($m, $revoked['number']));
    $this->postJson("/api/v1/cards/{$expired['id']}/prints")->assertUnprocessable();

    expect(CardPrint::count())->toBe(0);
});

it('logs a batch together, all or nothing', function () {
    [$a, $b, $c] = [($this->issue)(), ($this->issue)(), ($this->issue)()];
    $this->postJson("/api/v1/cards/{$c['id']}/revoke", ['note' => 'Left']);

    // One card cannot be printed: nothing is logged
    $this->postJson('/api/v1/cards/prints', ['cards' => [$a['id'], $b['id'], $c['id']]])
        ->assertUnprocessable()
        ->assertJsonPath('message', fn (string $m) => str_contains($m, $c['number']) && ! str_contains($m, $a['number']));
    expect(CardPrint::count())->toBe(0);

    $this->postJson('/api/v1/cards/prints', ['cards' => ['01JUNKNOWNCARD000000000000']])->assertJsonValidationErrors('cards');
    $this->postJson('/api/v1/cards/prints', ['cards' => []])->assertJsonValidationErrors('cards');

    $response = $this->postJson('/api/v1/cards/prints', ['cards' => [$b['id'], $a['id'], $a['id']]])->assertCreated()->assertJsonCount(2, 'data');
    expect($response->json('data.*.card.number'))->toBe([$b['number'], $a['number']])
        ->and($response->json('data.0.batch'))->toBeString()->toBe($response->json('data.1.batch'))
        ->and(Card::where('print_count', 1)->count())->toBe(2);
});

it('keeps print logs per organization and checks the permission', function () {
    $card = ($this->issue)();

    $viewer = tenantUser($this->tenant, 'Viewer');
    Sanctum::actingAs($viewer);
    $this->getJson("/api/v1/cards/{$card['id']}/prints")->assertOk();
    $this->postJson("/api/v1/cards/{$card['id']}/prints")->assertForbidden();
    $this->postJson('/api/v1/cards/prints', ['cards' => [$card['id']]])->assertForbidden();

    Sanctum::actingAs(tenantUser($this->tenant, 'Operator'));
    $this->postJson("/api/v1/cards/{$card['id']}/prints")->assertCreated();

    [, $stranger] = tenantWithOwner('globex', 'owner@globex.test');
    Sanctum::actingAs($stranger);
    $this->getJson("/api/v1/cards/{$card['id']}/prints")->assertNotFound();
    $this->postJson("/api/v1/cards/{$card['id']}/prints")->assertNotFound();
    $this->postJson('/api/v1/cards/prints', ['cards' => [$card['id']]])->assertJsonValidationErrors('cards');
    expect(CardPrint::withoutGlobalScopes()->count())->toBe(1);
});

it('finds every printable card the list filter matches, in the list order, up to one batch', function () {
    [$first, $second] = [($this->issue)(), ($this->issue)()];
    $revoked = ($this->issue)();
    $this->postJson("/api/v1/cards/{$revoked['id']}/revoke", ['note' => 'Left'])->assertOk();
    ($this->issue)(['issue_date' => '2020-01-01', 'expiry_date' => '2021-01-01']);
    $this->postJson("/api/v1/cards/{$first['id']}/prints")->assertCreated();

    // Revoked and expired cards are left out; the order is the list's
    $this->getJson('/api/v1/cards/printable?sort=number')
        ->assertOk()
        ->assertExactJson(['data' => [$first['id'], $second['id']], 'meta' => ['total' => 2, 'limit' => config('cards.print.max_batch')]]);
    $this->getJson('/api/v1/cards/printable?sort=-number')->assertJsonPath('data', [$second['id'], $first['id']]);
    // The same filters as the list: the "not printed yet" tile, then select all
    $this->getJson('/api/v1/cards/printable?filter[printed]=pending')->assertJsonPath('data', [$second['id']]);
    $this->getJson('/api/v1/cards/printable?filter[printed]=maybe')->assertJsonValidationErrors('filter.printed');

    config(['cards.print.max_batch' => 1]);
    $this->getJson('/api/v1/cards/printable?sort=number')->assertJsonPath('data', [$first['id']])->assertJsonPath('meta.total', 2);

    Sanctum::actingAs(tenantUser($this->tenant, 'Viewer'));
    $this->getJson('/api/v1/cards/printable')->assertForbidden();
});
