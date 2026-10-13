<?php

use App\Models\Qualification;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    Storage::fake(config('cardholder_files.disk'));
    [$this->tenant, $this->owner] = tenantWithOwner();
    Sanctum::actingAs($this->owner);
    $this->rank = masterItem($this->tenant, ['type' => 'rank', 'name' => 'Captain']);
    $jane = cardholder($this->tenant, ['full_name' => 'Jane Doe', 'employee_no' => 'E-1', 'rank_id' => $this->rank->id]);
    $john = cardholder($this->tenant, ['full_name' => 'John Roe', 'employee_no' => 'E-2']);
    $record = fn ($holder, array $attributes) => app(TenantContext::class)->run($this->tenant, fn () => Qualification::factory()->create(['tenant_id' => $this->tenant->id, 'cardholder_id' => $holder->id, ...$attributes]));
    $this->janeSsc = $record($jane, ['kind' => 'education', 'title' => 'SSC', 'institution' => 'Dhaka Board', 'subject' => 'Science', 'year' => 2005, 'result' => '5.00']);
    $this->janeHsc = $record($jane, ['kind' => 'education', 'title' => 'HSC', 'institution' => 'Dhaka Board', 'year' => 2007, 'result' => '4.80']);
    $this->johnCourse = $record($john, ['kind' => 'course', 'title' => 'First aid', 'institution' => 'Red Crescent', 'year' => 2020, 'result' => 'Passed']);
});

it('lists every education and training record of every cardholder, as the legacy lists did', function () {
    $ids = fn (string $query) => array_column($this->getJson("/api/v1/reports/qualifications?{$query}")->assertOk()->json('data'), 'id');

    expect($ids(''))->toBe([$this->johnCourse->public_id, $this->janeHsc->public_id, $this->janeSsc->public_id])
        ->and($ids('filter[kind]=course'))->toBe([$this->johnCourse->public_id])
        ->and($ids('filter[year_from]=2006&filter[year_to]=2010'))->toBe([$this->janeHsc->public_id])
        ->and($ids('filter[year_to]=2006'))->toBe([$this->janeSsc->public_id])
        ->and($ids('filter[search]=Dhaka&sort=year'))->toBe([$this->janeSsc->public_id, $this->janeHsc->public_id])
        ->and($ids('filter[search]=E-2'))->toBe([$this->johnCourse->public_id])
        ->and($ids("filter[rank]={$this->rank->public_id}"))->toHaveCount(2);

    $this->getJson('/api/v1/reports/qualifications?sort=year&columns[]=cardholder.full_name&columns[]=qualification.kind&columns[]=qualification.title&columns[]=qualification.subject&columns[]=qualification.year&columns[]=qualification.result')
        ->assertJsonPath('meta.totals', ['records' => 3, 'cardholders' => 2, 'education' => 2, 'courses' => 1])
        ->assertJsonPath('data.0.values', ['cardholder.full_name' => 'Jane Doe', 'qualification.kind' => 'Education', 'qualification.title' => 'SSC', 'qualification.subject' => 'Science', 'qualification.year' => '2005', 'qualification.result' => '5.00']);

    // A year range may not end before it starts
    $this->getJson('/api/v1/reports/qualifications?filter[year_from]=2010&filter[year_to]=2005&filter[kind]=exam')->assertJsonValidationErrors(['filter.year_to', 'filter.kind']);
});

it('needs the cardholders.view permission and downloads what it finds', function () {
    $this->get('/api/v1/reports/qualifications/export?format=csv&filter[kind]=course&columns[]=cardholder.full_name&columns[]=qualification.title&columns[]=qualification.year')
        ->assertOk()
        ->assertStreamedContent("\u{FEFF}\"Full name\",\"Exam / course\",Year\n\"John Roe\",\"First aid\",2020\n");

    $role = app(TenantContext::class)->run($this->tenant, fn () => Role::create(['name' => 'Report reader', 'guard_name' => 'web', 'tenant_id' => $this->tenant->id]));
    $role->givePermissionTo(['reports.view']);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    Sanctum::actingAs(tenantUser($this->tenant, 'Report reader'));
    $this->getJson('/api/v1/reports/qualifications')->assertForbidden();
});
