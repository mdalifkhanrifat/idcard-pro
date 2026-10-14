<?php

use App\Models\CustomField;
use App\Services\Reports\ReportRegistry;
use App\Services\Reports\ReportValueText;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use OpenSpout\Reader\CSV\Reader;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    Storage::fake(config('cardholder_files.disk'));
    [$this->tenant, $this->owner] = tenantWithOwner();
    Sanctum::actingAs($this->owner);
    $field = fn (array $attributes) => app(TenantContext::class)->run($this->tenant, fn () => CustomField::factory()->create(['tenant_id' => $this->tenant->id, 'entity' => 'cardholder', 'is_active' => true, ...$attributes]));
    $field(['key' => 'shift', 'label' => 'Shift', 'type' => 'select', 'options' => ['Day', 'Night']]);
    $field(['key' => 'vaccinated', 'label' => 'Vaccinated', 'type' => 'checkbox']);
    $field(['key' => 'locker_no', 'label' => 'Locker', 'type' => 'number']);

    $this->rank = masterItem($this->tenant, ['type' => 'rank', 'name' => 'Captain']);
    $religion = masterItem($this->tenant, ['type' => 'religion', 'name' => 'Faith']);
    $template = cardTemplate($this->tenant, ['code' => 'EMP']);
    $this->jane = cardholder($this->tenant, ['full_name' => 'Jane Doe', 'employee_no' => 'E-1', 'rank_id' => $this->rank->id, 'religion_id' => $religion->id, 'custom_fields' => ['shift' => 'Night', 'vaccinated' => true, 'locker_no' => 12]]);
    $this->john = cardholder($this->tenant, ['full_name' => 'John Roe', 'employee_no' => 'E-2', 'status' => 'retired', 'custom_fields' => ['shift' => 'Day', 'vaccinated' => false]]);
    $this->paul = cardholder($this->tenant, ['full_name' => 'Paul Poe', 'employee_no' => 'E-3']);
    // Jane's card printed, John's not yet, Paul has none
    $janeCard = $this->postJson("/api/v1/cardholders/{$this->jane->public_id}/cards", ['template' => $template->public_id, 'reason' => 'new'])->assertCreated()->json('data');
    $this->postJson("/api/v1/cards/{$janeCard['id']}/prints")->assertCreated();
    $this->johnCardNumber = $this->postJson("/api/v1/cardholders/{$this->john->public_id}/cards", ['template' => $template->public_id, 'reason' => 'new'])->assertCreated()->json('data.number');
    $this->janeCardNumber = $janeCard['number'];
});

it('describes the cardholder list as a report, every master data list and drop-down or yes/no custom field a filter', function () {
    $report = collect($this->getJson('/api/v1/reports')->json('data'))->keyBy('key')['cardholders'];
    $filters = collect($report['filters'])->keyBy('key');

    expect($report['default_columns'])->toBe(['cardholder.full_name', 'cardholder.employee_no', 'card.number', 'cardholder.rank', 'cardholder.department', 'cardholder.date_of_birth', 'cardholder.blood_group', 'cardholder.created_at', 'cardholder.created_by', 'cardholder.card_state'])
        ->and($filters->keys()->all())->toContain('search', 'status', 'employment_type', 'joined_from', 'joined_to', 'card', 'religion', 'trade', 'appointment', 'custom.shift', 'custom.vaccinated')
        ->and($filters->keys()->all())->not->toContain('custom.locker_no')
        ->and(array_column($filters['custom.shift']['options'], 'value'))->toBe(['Day', 'Night'])
        ->and(array_column($filters['custom.vaccinated']['options'], 'value'))->toBe(['true', 'false'])
        ->and(array_column($report['columns'], 'key'))->toContain('cardholder.custom.locker_no', 'cardholder.status', 'cardholder.card_state')
        ->and(collect($report['totals'])->pluck('key')->all())->toBe(['cardholders', 'no_card', 'pending', 'printed']);
});

it('finds what the cardholder list finds, custom fields included, with the legacy columns and print status', function () {
    $ids = fn (string $query) => array_column($this->getJson("/api/v1/reports/cardholders?{$query}")->assertOk()->json('data'), 'id');
    $listed = fn (string $query) => array_column($this->getJson("/api/v1/cardholders?{$query}")->assertOk()->json('data'), 'id');

    foreach (['filter[search]=jane', "filter[search]={$this->janeCardNumber}", 'filter[status]=retired', 'filter[card]=pending', 'filter[card]=none', "filter[rank]={$this->rank->public_id}", 'filter[custom][shift]=Night', 'filter[custom][vaccinated]=false', 'sort=full_name'] as $query) {
        expect($ids($query))->toBe($listed($query), $query);
    }
    expect($ids('filter[custom][shift]=Night'))->toBe([$this->jane->public_id]);

    $this->getJson('/api/v1/reports/cardholders?sort=full_name&columns[]=cardholder.full_name&columns[]=card.number&columns[]=cardholder.rank&columns[]=cardholder.religion&columns[]=cardholder.custom.shift&columns[]=cardholder.status&columns[]=cardholder.card_state&columns[]=cardholder.created_by')
        ->assertJsonPath('meta.totals', ['cardholders' => 3, 'no_card' => 1, 'pending' => 1, 'printed' => 1])
        ->assertJsonPath('data.0.values', [
            'cardholder.full_name' => 'Jane Doe', 'card.number' => $this->janeCardNumber, 'cardholder.rank' => 'Captain', 'cardholder.religion' => 'Faith',
            'cardholder.custom.shift' => 'Night', 'cardholder.status' => 'Active', 'cardholder.card_state' => 'Printed', 'cardholder.created_by' => null,
        ])
        ->assertJsonPath('data.1.values', [
            'cardholder.full_name' => 'John Roe', 'card.number' => $this->johnCardNumber, 'cardholder.rank' => null, 'cardholder.religion' => null,
            'cardholder.custom.shift' => 'Day', 'cardholder.status' => 'Retired', 'cardholder.card_state' => 'Not printed', 'cardholder.created_by' => null,
        ])
        ->assertJsonPath('data.2.values', [
            'cardholder.full_name' => 'Paul Poe', 'card.number' => null, 'cardholder.rank' => null, 'cardholder.religion' => null,
            'cardholder.custom.shift' => null, 'cardholder.status' => 'Active', 'cardholder.card_state' => 'No card', 'cardholder.created_by' => null,
        ]);

    $this->getJson('/api/v1/reports/cardholders?filter[custom][shift]=Evening&filter[custom][colour]=red')->assertJsonValidationErrors(['filter.custom.shift', 'filter.custom']);
});

it('shows cards only to those who may see cards, and needs the cardholders.view permission', function () {
    $role = app(TenantContext::class)->run($this->tenant, fn () => Role::create(['name' => 'Report reader', 'guard_name' => 'web', 'tenant_id' => $this->tenant->id]));
    $role->givePermissionTo(['reports.view', 'cardholders.view']);
    $reader = tenantUser($this->tenant, 'Report reader');
    Sanctum::actingAs($reader);

    $report = collect($this->getJson('/api/v1/reports')->json('data'))->keyBy('key')['cardholders'];
    expect(array_column($report['columns'], 'key'))->not->toContain('card.number', 'cardholder.card_state')
        ->and(array_column($report['filters'], 'key'))->not->toContain('card')
        ->and($report['default_columns'])->not->toContain('card.number');
    $this->getJson('/api/v1/reports/cardholders?columns[]=card.number')->assertJsonValidationErrors('columns.0');
    // Card numbers are not searched for them
    $this->getJson("/api/v1/reports/cardholders?filter[search]={$this->janeCardNumber}")->assertOk()->assertJsonCount(0, 'data');

    $role->revokePermissionTo('cardholders.view');
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $reader->refresh();
    expect(collect($this->getJson('/api/v1/reports')->json('data'))->pluck('key'))->not->toContain('cardholders');
    $this->getJson('/api/v1/reports/cardholders')->assertForbidden();
});

it('downloads the cardholders as the list finds them, kept in the audit log', function () {
    $response = $this->get('/api/v1/reports/cardholders/export?format=csv&filter[custom][vaccinated]=true&columns[]=cardholder.full_name&columns[]=cardholder.custom.locker_no')->assertOk();
    $path = tempnam(sys_get_temp_dir(), 'csv');
    file_put_contents($path, $response->streamedContent());
    $reader = new Reader;
    $reader->open($path);
    $rows = [];
    foreach ($reader->getSheetIterator() as $sheet) {
        foreach ($sheet->getRowIterator() as $row) {
            $rows[] = $row->toArray();
        }
    }
    $reader->close();
    unlink($path);

    expect($rows)->toBe([['Full name', 'Locker'], ['Jane Doe', '12']]);
    expect(Activity::query()->where('log_name', 'reports')->latest('id')->first()->properties->all())
        ->toMatchArray(['report' => 'cardholders', 'format' => 'csv', 'rows' => 1, 'filter' => ['custom.vaccinated' => 'true']]);
});

it('keeps the cardholder\'s days as days and number custom fields as numbers, in every output', function () {
    app(TenantContext::class)->run($this->tenant, fn () => CustomField::factory()->create(['tenant_id' => $this->tenant->id, 'entity' => 'cardholder', 'is_active' => true, 'key' => 'badge_on', 'label' => 'Badge on', 'type' => 'date']));
    $this->jane->update(['date_of_birth' => '1980-02-03', 'joining_date' => '2005-07-01', 'custom_fields' => [...$this->jane->custom_fields, 'badge_on' => '2024-12-31']]);
    // A value that is not a day (e.g. written before the field became a date) is left out rather than misread
    $this->john->update(['custom_fields' => [...$this->john->custom_fields, 'badge_on' => 'soon', 'locker_no' => 'n/a']]);
    $keys = ['cardholder.full_name', 'cardholder.date_of_birth', 'cardholder.joining_date', 'cardholder.custom.badge_on', 'cardholder.custom.locker_no'];
    $query = 'sort=full_name&filter[search]=o&'.collect($keys)->map(fn (string $key) => "columns[]={$key}")->implode('&');

    $types = collect($this->getJson('/api/v1/reports')->json('data'))->firstWhere('key', 'cardholders')['columns'];
    expect(collect($types)->whereIn('key', $keys)->pluck('type', 'key')->sortKeys()->all())->toBe([
        'cardholder.custom.badge_on' => 'date', 'cardholder.custom.locker_no' => 'number', 'cardholder.date_of_birth' => 'date',
        'cardholder.full_name' => 'text', 'cardholder.joining_date' => 'date',
    ]);
    $this->getJson("/api/v1/reports/cardholders?{$query}")
        ->assertJsonPath('data.0.values', ['cardholder.full_name' => 'Jane Doe', 'cardholder.date_of_birth' => '1980-02-03', 'cardholder.joining_date' => '2005-07-01', 'cardholder.custom.badge_on' => '2024-12-31', 'cardholder.custom.locker_no' => 12])
        ->assertJsonPath('data.1.values.cardholder.custom.badge_on', null)
        ->assertJsonPath('data.1.values.cardholder.custom.locker_no', null);

    // A spreadsheet gets real dates and numbers
    $response = $this->get("/api/v1/reports/cardholders/export?format=xlsx&{$query}")->assertOk();
    $file = tempnam(sys_get_temp_dir(), 'test').'.xlsx';
    copy($response->baseResponse->getFile()->getPathname(), $file);
    unlink($response->baseResponse->getFile()->getPathname());
    $reader = new OpenSpout\Reader\XLSX\Reader;
    $reader->open($file);
    $rows = [];
    foreach ($reader->getSheetIterator() as $sheet) {
        foreach ($sheet->getRowIterator() as $row) {
            $rows[] = $row->toArray();
        }
    }
    $reader->close();
    unlink($file);
    expect($rows[1][0])->toBe('Jane Doe')
        ->and($rows[1][1])->toBeInstanceOf(DateTimeImmutable::class)
        ->and($rows[1][1]->format('Y-m-d'))->toBe('1980-02-03')
        ->and($rows[1][3]->format('Y-m-d'))->toBe('2024-12-31')
        ->and($rows[1][4])->toBe(12);

    // Documents write them in the reader's digits, as every other day
    app()->setLocale('bn');
    $columns = app(ReportRegistry::class)->get('cardholders')->columns();
    $text = app(ReportValueText::class);
    expect($text->of($columns['cardholder.date_of_birth'], $columns['cardholder.date_of_birth']->valueOf($this->jane->fresh())))->toBe('০৩/০২/১৯৮০')
        ->and($text->of($columns['cardholder.custom.locker_no'], $columns['cardholder.custom.locker_no']->valueOf($this->jane->fresh())))->toBe('১২');
});
