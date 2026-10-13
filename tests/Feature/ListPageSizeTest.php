<?php

use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    [$this->tenant, $this->owner] = tenantWithOwner();
    Sanctum::actingAs($this->owner);
});

it('lets every list show as many rows per page as the legacy lists did, and no more', function (string $list) {
    $this->getJson("{$list}?per_page=2000")->assertOk()->assertJsonPath('meta.per_page', 2000);
    $this->getJson("{$list}?per_page=2001")->assertJsonValidationErrors('per_page');
    $this->getJson("{$list}?per_page=0")->assertJsonValidationErrors('per_page');
})->with([
    'cardholders' => '/api/v1/cardholders',
    'cards' => '/api/v1/cards',
    'users' => '/api/v1/users',
    'audit changes' => '/api/v1/audit/activities',
    'audit sign-ins' => '/api/v1/audit/logins',
    'master data' => '/api/v1/master-data/rank',
    'reports' => '/api/v1/reports/card-prints',
]);

it('takes the limit and the sizes offered from configuration', function () {
    config(['lists.max_per_page' => 300, 'lists.page_sizes' => [20, 100, 500]]);

    $this->getJson('/api/v1/cardholders?per_page=300')->assertOk();
    $this->getJson('/api/v1/cardholders?per_page=301')->assertJsonValidationErrors('per_page');
    // Sizes over the limit are not offered
    $this->getJson('/api/v1/auth/me')->assertJsonPath('data.lists', ['page_sizes' => [20, 100], 'max_per_page' => 300]);
});

it('keeps the import history within the limit too', function () {
    config(['lists.max_per_page' => 50]);

    $this->getJson('/api/v1/cardholder-imports?per_page=100000')->assertOk()->assertJsonPath('meta.per_page', 50);
});
