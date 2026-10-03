<?php

use App\Models\Cardholder;
use App\Models\CardholderImport;
use App\Models\CustomField;
use App\Models\MasterDataItem;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    Storage::fake(config('cardholder_files.disk'));
    [$this->tenant, $this->owner] = tenantWithOwner();
});

function csvUpload(array $rows, string $name = 'people.csv'): UploadedFile
{
    $handle = fopen('php://temp', 'r+');
    foreach ($rows as $row) {
        fputcsv($handle, $row, ',', '"', '');
    }
    rewind($handle);

    return UploadedFile::fake()->createWithContent($name, stream_get_contents($handle));
}

function analyze(array $rows, array $options = []): TestResponse
{
    return test()->post('/api/v1/cardholder-imports', ['file' => csvUpload($rows), ...$options], ['Accept' => 'application/json']);
}

it('analyzes a file without writing anything, then imports the valid rows', function () {
    Sanctum::actingAs($this->owner);
    masterItem($this->tenant, ['type' => 'rank', 'name' => 'Captain', 'code' => 'CPT']);

    $response = analyze([
        ['Name', 'Service No', 'TS No', 'Rank', 'bg_group', 'DOB', 'Shoe size'],
        ['Rahim Uddin', 'S-1', 'T-1', 'captain', 'A+', '15/04/1990', '42'],
        ['Karim Mia', 'S-2', '', 'CPT', 'O+', '1988-01-31', ''],
        ['', 'S-3', '', '', '', '', ''],
        ['Bad Rank', 'S-4', '', 'Admiral', '', '31/02/1990', ''],
    ])
        ->assertCreated()
        ->assertJsonPath('status', null)
        ->assertJsonPath('data.status', 'analyzed')
        ->assertJsonPath('data.total_rows', 4)
        ->assertJsonPath('data.valid_rows', 2)
        ->assertJsonPath('data.summary.ignored', ['Shoe size'])
        ->assertJsonPath('data.summary.preview.0.values.full_name', 'Rahim Uddin')
        ->assertJsonPath('data.errors.0.row', 4)
        ->assertJsonPath('data.errors.1.row', 5);

    // Row 5: unknown rank and an impossible date.
    expect($response->json('data.errors.1.messages'))->toHaveCount(2)
        ->and(Cardholder::count())->toBe(0);

    $id = $response->json('data.id');
    $this->postJson("/api/v1/cardholder-imports/{$id}/start")->assertOk();

    $this->getJson("/api/v1/cardholder-imports/{$id}")
        ->assertJsonPath('data.status', 'completed')
        ->assertJsonPath('data.created_count', 2)
        ->assertJsonPath('data.failed_count', 2);

    $rahim = Cardholder::with(['rank', 'bloodGroup'])->where('employee_no', 'S-1')->firstOrFail();
    expect($rahim->secondary_no)->toBe('T-1')
        ->and($rahim->rank->name)->toBe('Captain')
        ->and($rahim->bloodGroup->name)->toBe('A+')
        ->and($rahim->date_of_birth->toDateString())->toBe('1990-04-15')
        ->and($rahim->created_by)->toBe($this->owner->id)
        ->and(CardholderImport::first()->path)->toBeNull();

    $this->postJson("/api/v1/cardholder-imports/{$id}/start")->assertUnprocessable();
});

it('creates missing master data when asked, inside the right parent', function () {
    Sanctum::actingAs($this->owner);

    $response = analyze([
        ['Full name', 'Department', 'Unit', 'District', 'Upazila'],
        ['A', 'Logistics', 'Stores', 'Dhaka', 'Savar'],
        ['B', 'Logistics', 'Transport', 'Dhaka', 'Savar'],
    ], ['create_missing_lookups' => 1])->assertJsonPath('data.valid_rows', 2);
    $id = $response->json('data.id');

    expect($response->json('data.summary.lookups_to_create'))
        ->toEqualCanonicalizing(['department: Logistics', 'region: Dhaka', 'unit: Stores', 'sub_region: Savar', 'unit: Transport']);
    expect(MasterDataItem::where('type', 'department')->count())->toBe(0);

    $this->postJson("/api/v1/cardholder-imports/{$id}/start")->assertOk();

    $logistics = MasterDataItem::where('type', 'department')->where('name', 'Logistics')->sole();
    expect(MasterDataItem::where('type', 'unit')->where('parent_id', $logistics->id)->pluck('name')->sort()->values()->all())->toBe(['Stores', 'Transport'])
        ->and(MasterDataItem::where('type', 'sub_region')->count())->toBe(1)
        ->and(Cardholder::with('unit')->where('full_name', 'B')->first()->unit->name)->toBe('Transport');
});

it('rejects unknown master data unless creation is allowed', function () {
    Sanctum::actingAs($this->owner);

    analyze([['Name', 'Department', 'Unit'], ['A', 'Nowhere', 'X']])
        ->assertJsonPath('data.valid_rows', 0)
        ->assertJsonCount(2, 'data.errors.0.messages');
});

it('updates existing cardholders by employee no when asked', function () {
    Sanctum::actingAs($this->owner);
    cardholder($this->tenant, ['employee_no' => 'E-1', 'full_name' => 'Old Name', 'personal_phone' => '0100']);

    analyze([['Name', 'Employee no'], ['New Name', 'E-1']])->assertJsonPath('data.valid_rows', 0);

    $id = analyze([['Name', 'Employee no', 'Phone'], ['New Name', 'E-1', '']], ['update_existing' => 1])
        ->assertJsonPath('data.valid_rows', 1)
        ->assertJsonPath('data.summary.preview.0.action', 'updated')
        ->json('data.id');
    $this->postJson("/api/v1/cardholder-imports/{$id}/start")->assertOk();

    expect(Cardholder::sole())->full_name->toBe('New Name')->personal_phone->toBeNull();
    $this->getJson("/api/v1/cardholder-imports/{$id}")->assertJsonPath('data.updated_count', 1);
});

it('flags duplicate employee numbers inside the file', function () {
    Sanctum::actingAs($this->owner);

    analyze([['Name', 'Employee no'], ['A', 'E-9'], ['B', 'e-9']])
        ->assertJsonPath('data.valid_rows', 1)
        ->assertJsonPath('data.errors.0.row', 3);
});

it('imports custom fields and xlsx workbooks', function () {
    Sanctum::actingAs($this->owner);
    app(TenantContext::class)->run($this->tenant, fn () => CustomField::factory()->create(['tenant_id' => $this->tenant->id, 'key' => 'class', 'label' => 'Class']));

    $path = tempnam(sys_get_temp_dir(), 'x');
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::OVERWRITE);
    $zip->addFromString('xl/worksheets/sheet1.xml', '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'
        .'<row r="1"><c r="A1" t="inlineStr"><is><t>Name</t></is></c><c r="B1" t="inlineStr"><is><t>Class</t></is></c><c r="C1" t="inlineStr"><is><t>Joining date</t></is></c></row>'
        .'<row r="2"><c r="A2" t="inlineStr"><is><t>Student One</t></is></c><c r="B2" t="inlineStr"><is><t>Ten</t></is></c><c r="C2"><v>45292</v></c></row>'
        .'</sheetData></worksheet>');
    $zip->close();

    $id = $this->post('/api/v1/cardholder-imports', ['file' => new UploadedFile($path, 'students.xlsx', null, null, true)], ['Accept' => 'application/json'])
        ->assertCreated()
        ->assertJsonPath('data.valid_rows', 1)
        ->json('data.id');
    $this->postJson("/api/v1/cardholder-imports/{$id}/start")->assertOk();

    $student = Cardholder::sole();
    expect($student->custom_fields)->toBe(['class' => 'Ten'])
        ->and($student->joining_date->toDateString())->toBe('2024-01-01');
});

it('rejects files without a name column or of the wrong type', function () {
    Sanctum::actingAs($this->owner);

    analyze([['Phone'], ['0171']])->assertJsonValidationErrors('file');
    $this->post('/api/v1/cardholder-imports', ['file' => UploadedFile::fake()->createWithContent('x.pdf', '%PDF-1.4')], ['Accept' => 'application/json'])
        ->assertJsonValidationErrors('file');
    expect(CardholderImport::count())->toBe(0);
});

it('discards analyzed imports and keeps imports per organization', function () {
    Sanctum::actingAs($this->owner);
    $id = analyze([['Name'], ['A']])->json('data.id');
    $path = CardholderImport::where('public_id', $id)->value('path');

    [$otherTenant, $otherOwner] = tenantWithOwner('beta', 'b@example.com');
    Sanctum::actingAs($otherOwner);
    $this->getJson("/api/v1/cardholder-imports/{$id}")->assertNotFound();
    $this->getJson('/api/v1/cardholder-imports')->assertJsonCount(0, 'data');

    Sanctum::actingAs($this->owner);
    $this->getJson('/api/v1/cardholder-imports')->assertJsonCount(1, 'data');
    $this->deleteJson("/api/v1/cardholder-imports/{$id}")->assertNoContent();
    Storage::disk(config('cardholder_files.disk'))->assertMissing($path);
});

it('requires the import permission and offers a template', function () {
    Sanctum::actingAs(tenantUser($this->tenant, 'Operator'));
    analyze([['Name'], ['A']])->assertForbidden();
    $this->get('/api/v1/cardholder-imports/template')->assertForbidden();

    Sanctum::actingAs($this->owner);
    $csv = $this->get('/api/v1/cardholder-imports/template')->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8')->getContent();
    expect($csv)->toStartWith("\xEF\xBB\xBF\"Full name\",");
});
