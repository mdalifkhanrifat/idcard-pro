<?php

use App\Services\Cards\CardRenderService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    Storage::fake(config('cardholder_files.disk'));
    [$this->tenant, $this->owner] = tenantWithOwner();
    $this->template = cardTemplate($this->tenant, ['code' => 'EMP']);
    $this->issue = fn () => $this->postJson('/api/v1/cardholders/'.cardholder($this->tenant)->public_id.'/cards', ['template' => $this->template->public_id, 'reason' => 'new'])->json('data');
    Sanctum::actingAs($this->owner);
    $this->link = fn (array $ids, string $sides = 'both') => app(TenantContext::class)->run($this->tenant, fn () => app(CardRenderService::class)->link($ids, $sides));
    // The render page forwards its own query to the API
    $this->apiPath = fn (string $link) => '/api/v1/card-render?'.parse_url($link, PHP_URL_QUERY);
});

it('gives the render page the cards to draw through a signed link, without a signed-in user', function () {
    [$a, $b] = [($this->issue)(), ($this->issue)()];
    $link = ($this->link)([$b['id'], $a['id']], 'front');
    expect($link)->toStartWith('http://web/render/cards?');

    $this->app['auth']->forgetGuards();
    $data = $this->getJson(($this->apiPath)($link))->assertOk()->json('data');

    expect(array_column($data['cards'], 'number'))->toBe([$b['number'], $a['number']])
        ->and($data['cards'][0])->toHaveKeys(['id', 'printable', 'layout.size_mm', 'layout.elements', 'values.fields', 'values.images'])
        ->and($data['cards'][0]['values']['fields']['card.number'])->toBe($b['number'])
        ->and($data['fonts'])->toBeArray()
        ->and($data['sides'])->toBe('front');
});

it('refuses a changed or expired link', function () {
    [$a, $b] = [($this->issue)(), ($this->issue)()];
    $path = ($this->apiPath)(($this->link)([$a['id']]));

    $this->getJson(str_replace($a['id'], $b['id'], $path))->assertForbidden();
    $this->getJson(str_replace('sides=both', 'sides=back', $path))->assertForbidden();

    $this->travel(config('pdf.link_minutes') + 1)->minutes();
    $this->getJson($path)->assertForbidden();
});

it('draws only cards of the organization in the link and says which cannot be printed', function () {
    $active = ($this->issue)();
    $revoked = ($this->issue)();
    $this->postJson("/api/v1/cards/{$revoked['id']}/revoke", ['note' => 'Left'])->assertOk();

    [$other, $stranger] = tenantWithOwner('globex', 'owner@globex.test');
    Sanctum::actingAs($stranger);
    $foreign = $this->postJson('/api/v1/cardholders/'.cardholder($other)->public_id.'/cards', ['template' => cardTemplate($other)->public_id, 'reason' => 'new'])->json('data');

    $data = $this->getJson(($this->apiPath)(($this->link)([$active['id'], $foreign['id'], $revoked['id']])))->assertOk()->json('data');

    expect(array_column($data['cards'], 'id'))->toBe([$active['id'], $revoked['id']])
        ->and(array_column($data['cards'], 'printable'))->toBe([true, false]);
});
