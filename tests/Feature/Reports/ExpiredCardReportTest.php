<?php

use App\Models\Card;
use App\Models\FamilyMember;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    Storage::fake(config('cardholder_files.disk'));
    [$this->tenant, $this->owner] = tenantWithOwner();
    $this->tenant->update(['timezone' => 'Asia/Dhaka']);
    $this->owner->setRelation('tenant', $this->tenant);
    $this->travelTo(CarbonImmutable::parse('2026-04-10 10:00', 'Asia/Dhaka'));
    $this->rank = masterItem($this->tenant, ['type' => 'rank', 'name' => 'Captain']);
    $branch = masterItem($this->tenant, ['type' => 'branch', 'name' => 'Air']);
    $template = cardTemplate($this->tenant, ['code' => 'EMP']);
    $familyTemplate = cardTemplate($this->tenant, ['code' => 'FAM', 'audience' => 'family_member']);
    Sanctum::actingAs($this->owner);

    $issue = fn ($holder, array $extra) => $this->postJson("/api/v1/cardholders/{$holder->public_id}/cards", ['template' => $template->public_id, 'reason' => 'new', ...$extra])->assertCreated()->json('data');
    $jane = cardholder($this->tenant, ['full_name' => 'Jane Doe', 'employee_no' => 'S-1', 'rank_id' => $this->rank->id, 'branch_id' => $branch->id]);
    $john = cardholder($this->tenant, ['full_name' => 'John Roe', 'employee_no' => 'S-2']);
    $paul = cardholder($this->tenant, ['full_name' => 'Paul Poe', 'employee_no' => 'S-3']);
    // Jane's first card was replaced (not counted), her current one expired on 1 March
    $issue($jane, ['issue_date' => '2023-01-01', 'expiry_date' => '2024-01-01']);
    $this->expired = $issue($jane, ['reason' => 'renewal', 'issue_date' => '2024-03-01', 'expiry_date' => '2026-03-01']);
    // John's card expires in 10 days; his daughter's expired yesterday
    $this->expiring = $issue($john, ['issue_date' => '2024-04-20', 'expiry_date' => '2026-04-20']);
    $member = app(TenantContext::class)->run($this->tenant, fn () => FamilyMember::factory()->create(['tenant_id' => $this->tenant->id, 'cardholder_id' => $john->id, 'name' => 'Baby Roe', 'relation' => 'daughter']));
    $this->family = $this->postJson("/api/v1/cardholders/{$john->public_id}/cards", ['template' => $familyTemplate->public_id, 'family_member' => $member->public_id, 'reason' => 'new', 'issue_date' => '2025-04-09', 'expiry_date' => '2026-04-09'])->assertCreated()->json('data');
    // Paul's card is valid for years: not this report's business
    $issue($paul, ['issue_date' => '2026-01-01', 'expiry_date' => '2030-01-01']);
});

it('lists current cards past their last day with the legacy columns, longest overdue first', function () {
    $rows = $this->getJson('/api/v1/reports/expired-cards?'.implode('&', array_map(fn ($c) => "columns[]={$c}", ['holder.name', 'cardholder.rank', 'cardholder.branch', 'cardholder.employee_no', 'card.number', 'card.issue_date', 'card.expiry_date', 'card.days_overdue', 'family.relation'])))
        ->assertOk()
        ->assertJsonPath('meta.totals', ['cards' => 2, 'cardholders' => 2, 'family_cards' => 1])
        ->json('data');

    expect(array_column($rows, 'id'))->toBe([$this->expired['id'], $this->family['id']])
        ->and($rows[0]['values'])->toMatchArray([
            'holder.name' => 'Jane Doe', 'cardholder.rank' => 'Captain', 'cardholder.branch' => 'Air', 'cardholder.employee_no' => 'S-1',
            'card.number' => $this->expired['number'], 'card.issue_date' => '2024-03-01', 'card.expiry_date' => '2026-03-01', 'card.days_overdue' => 40,
        ])
        // The daughter's card expired yesterday in the organization's days
        ->and($rows[1]['values'])->toMatchArray(['holder.name' => 'Baby Roe', 'family.relation' => 'Daughter', 'card.days_overdue' => 1])
        ->and($rows[1]['family_member']['name'])->toBe('Baby Roe')
        ->and($rows[1]['cardholder']['full_name'])->toBe('John Roe');
});

it('looks ahead at cards expiring soon, and filters by dates, card type, holder, master data and search', function () {
    $this->getJson('/api/v1/reports/expired-cards?filter[validity]=expiring&filter[expiring_days]=15&columns[]=card.days_overdue')
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $this->expiring['id'])
        // Negative: days still left
        ->assertJsonPath('data.0.values', ['card.days_overdue' => -10]);
    $this->getJson('/api/v1/reports/expired-cards?filter[validity]=expiring&filter[expiring_days]=5')->assertJsonCount(0, 'data');
    $this->getJson('/api/v1/reports/expired-cards?filter[expiry_from]=2026-04-01&filter[expiry_to]=2026-04-30')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $this->family['id']);
    $this->getJson('/api/v1/reports/expired-cards?filter[holder]=family')->assertJsonCount(1, 'data');
    $this->getJson('/api/v1/reports/expired-cards?filter[reason]=renewal')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $this->expired['id']);
    $this->getJson('/api/v1/reports/expired-cards?filter[reason]=new')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $this->family['id']);
    $this->getJson("/api/v1/reports/expired-cards?filter[rank]={$this->rank->public_id}")->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $this->expired['id']);
    $this->getJson('/api/v1/reports/expired-cards?filter[search]=S-2')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $this->family['id']);
    $this->getJson('/api/v1/reports/expired-cards?sort=-expiry_date')->assertJsonPath('data.0.id', $this->family['id']);
});

it('filters by issue dates and, like the legacy From / To, by the organization\'s days the card was created, the last day whole', function () {
    // Jane's card recorded late on 5 April and the daughter's just after midnight on 6 April, Dhaka time (both 5 April in UTC)
    Card::query()->withoutGlobalScopes()->where('public_id', $this->expired['id'])->update(['created_at' => CarbonImmutable::parse('2026-04-05 23:30', 'Asia/Dhaka')->utc()]);
    Card::query()->withoutGlobalScopes()->where('public_id', $this->family['id'])->update(['created_at' => CarbonImmutable::parse('2026-04-06 00:30', 'Asia/Dhaka')->utc()]);

    $this->getJson('/api/v1/reports/expired-cards?filter[created_from]=2026-04-06&filter[created_to]=2026-04-06')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $this->family['id']);
    $this->getJson('/api/v1/reports/expired-cards?filter[created_to]=2026-04-05')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $this->expired['id']);
    $this->getJson('/api/v1/reports/expired-cards?filter[created_from]=2026-04-05')->assertJsonCount(2, 'data');
    $this->getJson('/api/v1/reports/expired-cards?filter[issued_from]=2025-01-01')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $this->family['id']);
    $this->getJson('/api/v1/reports/expired-cards?filter[issued_to]=2024-12-31')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $this->expired['id']);
    $this->getJson('/api/v1/reports/expired-cards?filter[created_from]=2026-04-06&filter[created_to]=2026-04-05&filter[issued_from]=2025-01-01&filter[issued_to]=2024-01-01')
        ->assertJsonValidationErrors(['filter.created_to', 'filter.issued_to']);
});

it('describes its filters, the days only for cards expiring soon', function () {
    $filters = collect(collect($this->getJson('/api/v1/reports')->json('data'))->keyBy('key')['expired_cards']['filters'])->keyBy('key');

    expect($filters['validity'])->toMatchArray(['type' => 'select', 'default' => 'expired'])
        ->and($filters['expiring_days'])->toMatchArray(['type' => 'number', 'min' => 1, 'max' => 365, 'default' => '30', 'when' => ['validity' => 'expiring']])
        // Legacy "Card Type": one option per configured reason, labelled like the column
        ->and($filters['reason'])->toMatchArray(['type' => 'select', 'label' => 'Card type (reason)'])
        ->and(array_column($filters['reason']['options'], 'value'))->toBe(array_keys(config('cards.reasons')));
});

it('validates the filter, naming each as the report labels it', function () {
    $errors = $this->getJson('/api/v1/reports/expired-cards?filter[validity]=valid&filter[expiring_days]=0&filter[expiry_from]=2026-05-01&filter[expiry_to]=2026-04-01&sort=copies')
        ->assertJsonValidationErrors(['filter.validity', 'filter.expiring_days', 'filter.expiry_to', 'sort'])
        ->json('errors');

    expect($errors['filter.expiring_days'][0])->toBe('The Expiring within (days) field must be between 1 and 365.')
        ->and($errors['filter.expiry_to'][0])->toBe('The Expiry date to field must be a date after or equal to Expiry date from.');
});

it('downloads expired cards with the legacy columns, kept in the audit log', function () {
    $response = $this->get('/api/v1/reports/expired-cards/export?format=csv')->assertOk();
    $path = $response->baseResponse->getFile()->getPathname();
    $lines = array_map(str_getcsv(...), explode("\n", trim(substr((string) file_get_contents($path), 3))));
    unlink($path);

    expect($lines[0])->toBe(['Name', 'Rank / grade', 'Branch', 'Employee no', 'Card no', 'Issue date', 'Expiry date', 'Created at', 'Days past expiry'])
        ->and(count($lines))->toBe(3)
        ->and(array_slice($lines[1], 0, 7))->toBe(['Jane Doe', 'Captain', 'Air', 'S-1', $this->expired['number'], '2024-03-01', '2026-03-01'])
        ->and($lines[1][8])->toBe('40')
        ->and(Activity::query()->where('log_name', 'reports')->sole()->properties['report'])->toBe('expired_cards');
});
