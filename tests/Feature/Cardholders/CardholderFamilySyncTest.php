<?php

use App\Models\Cardholder;
use App\Models\FamilyMember;
use App\Models\MasterDataItem;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Laravel\Sanctum\Sanctum;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;

/*
| 6.3e-1: a record of the HR sync may carry the person's family. Members are found by our id, by the HR system's
| id (external_id) or by relation and name; members the list leaves out are kept. Real bearer tokens of an
| integration account whose role may only sync.
*/

beforeEach(function () {
    [$this->tenant, $this->owner] = tenantWithOwner();
    $this->hr = app(TenantContext::class)->run($this->tenant, function (): User {
        Role::create(['name' => 'HR sync', 'guard_name' => 'web', 'tenant_id' => $this->tenant->id])->givePermissionTo('cardholders.sync');

        return User::factory()->integration()->create(['tenant_id' => $this->tenant->id, 'name' => 'Payroll'])->assignRole('HR sync');
    });
    $this->token = $this->hr->createToken('Main', ['*'], now()->addYear())->plainTextToken;
    $this->sync = function (array $body, array $headers = []) {
        app('auth')->forgetGuards();
        app(TenantContext::class)->set(null);

        return $this->withToken($this->token)->postJson('/api/v1/sync/cardholders', $body, $headers);
    };
    $this->members = fn (Cardholder $person): array => FamilyMember::query()->withoutGlobalScopes()->where('cardholder_id', $person->id)->orderBy('id')
        ->get()->map(fn (FamilyMember $member) => [$member->relation->value, $member->name, $member->external_id])->all();
    $this->member = fn (array $attributes, Cardholder $person) => FamilyMember::factory()->create(['tenant_id' => $this->tenant->id, 'cardholder_id' => $person->id, ...$attributes]);
});

it('creates a person with their family, master data by name, as the integration account', function () {
    masterItem($this->tenant, ['type' => 'blood_group', 'name' => 'B+', 'code' => 'BP']);

    $answer = ($this->sync)(['records' => [[
        'employee_no' => 'E-1', 'full_name' => 'Rahim',
        'family' => [
            ['external_id' => 'F-10', 'relation' => 'spouse', 'name' => 'Salma', 'blood_group' => 'B+', 'date_of_birth' => '1990-01-02', 'phone' => 1700000000],
            ['relation' => 'son', 'name' => 'Rafi', 'blood_group' => ['code' => 'BP']],
        ],
    ]]])->assertOk();

    $person = Cardholder::query()->withoutGlobalScopes()->where('employee_no', 'E-1')->sole();
    expect($answer->json('data.records.0.result'))->toBe('created')
        ->and(collect($answer->json('data.records.0.family'))->map(fn (array $member) => [$member['index'], $member['external_id'], $member['result']])->all())
        ->toBe([[0, 'F-10', 'created'], [1, null, 'created']])
        ->and(($this->members)($person))->toBe([['spouse', 'Salma', 'F-10'], ['son', 'Rafi', null]]);

    $spouse = FamilyMember::query()->withoutGlobalScopes()->where('external_id', 'F-10')->sole();
    expect($answer->json('data.records.0.family.0.id'))->toBe($spouse->public_id)
        ->and([$spouse->phone, $spouse->date_of_birth->toDateString(), $spouse->bloodGroup->name, $spouse->sort_order])->toBe(['1700000000', '1990-01-02', 'B+', 1]);

    $made = Activity::query()->where('log_name', 'family_members')->where('subject_id', $spouse->id)->where('event', 'created')->sole();
    expect($made->causer_id)->toBe($this->hr->id)
        ->and($made->attribute_changes['attributes']['external_id'] ?? null)->toBe('F-10');
});

it('links a member recorded by hand on the first sync, then follows them by the HR id, and keeps members left out', function () {
    $person = cardholder($this->tenant, ['employee_no' => 'E-1', 'full_name' => 'Rahim']);
    $byHand = ($this->member)(['relation' => 'spouse', 'name' => 'Salma Begum', 'phone' => '01711'], $person);
    $other = ($this->member)(['relation' => 'mother', 'name' => 'Amena'], $person);

    // Relation and name match, whatever the case: the member takes the HR id instead of being made twice
    ($this->sync)(['records' => [['employee_no' => 'E-1', 'family' => [['external_id' => 'F-10', 'relation' => 'spouse', 'name' => ' salma begum ']]]]])
        ->assertOk()
        ->assertJsonPath('data.records.0.result', 'updated')
        ->assertJsonPath('data.records.0.family.0.id', $byHand->public_id)
        ->assertJsonPath('data.records.0.family.0.result', 'updated');

    // The HR id finds them even when the name changes; what the item leaves out (the phone) stays
    ($this->sync)(['records' => [['employee_no' => 'E-1', 'family' => [['external_id' => 'F-10', 'name' => 'Salma Akter']]]]])
        ->assertOk()
        ->assertJsonPath('data.records.0.family.0.id', $byHand->public_id)
        ->assertJsonPath('data.records.0.family.0.result', 'updated');

    expect(($this->members)($person))->toBe([['spouse', 'Salma Akter', 'F-10'], ['mother', 'Amena', null]])
        ->and($byHand->fresh()->phone)->toBe('01711')
        ->and($other->fresh())->not->toBeNull();

    // Nothing new: the record is unchanged, and so is the member
    ($this->sync)(['records' => [['employee_no' => 'E-1', 'family' => [['external_id' => 'F-10', 'name' => 'Salma Akter']]]]])
        ->assertOk()
        ->assertJsonPath('data.records.0.result', 'unchanged')
        ->assertJsonPath('data.records.0.family.0.result', 'unchanged');
});

it('finds a member by the id the API gives, and takes a member back the way the API gave it', function () {
    $person = cardholder($this->tenant, ['employee_no' => 'E-1']);
    $member = ($this->member)(['relation' => 'son', 'name' => 'Rafi'], $person);

    Sanctum::actingAs($this->owner);
    $given = $this->getJson("/api/v1/cardholders/{$person->public_id}/family-members")->assertOk()->json('data.0');

    ($this->sync)(['records' => [['employee_no' => 'E-1', 'family' => [[...$given, 'name' => 'Rafiul', 'relation' => 'son']]]]])
        ->assertOk()
        ->assertJsonPath('data.records.0.result', 'updated')
        ->assertJsonPath('data.records.0.family.0.id', $member->public_id);

    expect(($this->members)($person))->toBe([['son', 'Rafiul', null]]);
});

it('says in a dry run what would become of each member, and writes nothing', function () {
    $person = cardholder($this->tenant, ['employee_no' => 'E-1']);
    ($this->member)(['relation' => 'son', 'name' => 'Rafi'], $person);

    ($this->sync)(['dry_run' => true, 'create_missing_lookups' => true, 'records' => [['employee_no' => 'E-1', 'family' => [
        ['relation' => 'son', 'name' => 'Rafi', 'blood_group' => 'Q+'],
        ['relation' => 'daughter', 'name' => 'Rima'],
    ]]]])
        ->assertOk()
        ->assertJsonPath('data.records.0.family.0.result', 'updated')
        ->assertJsonPath('data.records.0.family.1.result', 'created')
        ->assertJsonPath('data.records.0.family.1.id', null)
        ->assertJsonPath('data.lookups_created', ['blood_group: Q+']);

    expect(($this->members)($person))->toBe([['son', 'Rafi', null]])
        ->and(MasterDataItem::query()->withoutGlobalScopes()->where('name', 'Q+')->exists())->toBeFalse();
});

it('names every family problem by its place, and saves nothing of a record with one', function () {
    $person = cardholder($this->tenant, ['employee_no' => 'E-1', 'full_name' => 'Rahim']);
    ($this->member)(['relation' => 'son', 'name' => 'Rafi'], $person);
    ($this->member)(['relation' => 'son', 'name' => 'Rafi'], $person);
    $linked = ($this->member)(['relation' => 'mother', 'name' => 'Amena', 'external_id' => 'F-1'], $person);

    $answer = ($this->sync)(['records' => [
        ['employee_no' => 'E-1', 'full_name' => 'Rahim Uddin', 'family' => [
            ['relation' => 'daughter'],
            ['relation' => 'son', 'name' => 'Rafi'],
            ['id' => '01ARZ3NDEKTSV4RRFFQ69G5FAV', 'name' => 'X'],
            ['external_id' => 'F-1', 'shoe' => 42],
            ['external_id' => 'f-1', 'relation' => 'mother', 'name' => 'Amena'],
            'not a member',
            ['relation' => 'cousin', 'name' => 'Y', 'date_of_birth' => '01/02/1990'],
        ]],
        ['employee_no' => 'E-2', 'full_name' => 'B', 'family' => 'none'],
    ]])->assertOk();

    $errors = $answer->json('data.records.0.errors');
    expect($answer->json('data.records.0.result'))->toBe('failed')
        ->and($answer->json('data.records.0.family'))->toBe([])
        ->and(array_keys($errors))->toEqualCanonicalizing([
            'family.0.name', 'family.1.name', 'family.2.id', 'family.3.shoe', 'family.4.external_id', 'family.4.id', 'family.5',
            'family.6.relation', 'family.6.date_of_birth',
        ])
        ->and($errors['family.0.name'][0])->toContain('Member name')
        ->and($errors['family.1.name'][0])->toBe('More than one family member is Son “Rafi”: send external_id or id to say which.')
        ->and($errors['family.4.id'][0])->toBe('This family member is already given at family.3.')
        ->and($answer->json('data.records.1.errors.family.0'))->toBe('family must be a list of family members.');

    // The person's own change is undone with the family
    expect(Cardholder::query()->withoutGlobalScopes()->where('employee_no', 'E-1')->sole()->full_name)->toBe('Rahim')
        ->and($linked->fresh()->name)->toBe('Amena')
        ->and(Cardholder::query()->withoutGlobalScopes()->where('employee_no', 'E-2')->exists())->toBeFalse();
});

it('holds no more members than allowed, and answers in the reader\'s language', function () {
    config(['cardholder_sync.max_family' => 1]);

    ($this->sync)(['records' => [['employee_no' => 'E-1', 'full_name' => 'A', 'family' => [['relation' => 'son', 'name' => 'A'], ['relation' => 'son', 'name' => 'B']]]]])
        ->assertOk()
        ->assertJsonPath('data.records.0.errors.family.0', 'A record can hold at most 1 family members.');

    ($this->sync)(['records' => [['employee_no' => 'E-1', 'full_name' => 'A', 'family' => [['relation' => 'son', 'name' => 'A', 'eye' => 'x']]]]], ['Accept-Language' => 'bn'])
        ->assertOk()
        ->assertJsonPath('data.records.0.errors', ['family.0.eye' => ['“eye” পরিবারের সদস্যের কোনো ঘর নয়।']]);

    // A birth date in the future says "today" in words, for the member and the person alike
    $errors = ($this->sync)(['records' => [['employee_no' => 'E-1', 'full_name' => 'A', 'date_of_birth' => '2099-01-01', 'family' => [['relation' => 'son', 'name' => 'A', 'date_of_birth' => '2099-01-01']]]]], ['Accept-Language' => 'bn'])
        ->assertOk()
        ->json('data.records.0.errors');
    expect($errors['family.0.date_of_birth'][0])->toBe('জন্ম তারিখ আজ বা তার আগের তারিখ হতে হবে।')
        ->and($errors['date_of_birth'][0])->toBe('জন্ম তারিখ অবশ্যই আজকের আগের তারিখ হতে হবে।');
});

it('lets the form set the HR id too, one per member of a cardholder', function () {
    $person = cardholder($this->tenant, ['employee_no' => 'E-1']);
    $neighbour = cardholder($this->tenant, ['employee_no' => 'E-2']);
    ($this->member)(['relation' => 'son', 'name' => 'Rafi', 'external_id' => 'F-1'], $neighbour);
    Sanctum::actingAs($this->owner);
    $base = "/api/v1/cardholders/{$person->public_id}/family-members";

    $id = $this->postJson($base, ['relation' => 'son', 'name' => 'Rafi', 'external_id' => 'F-1'])->assertCreated()->assertJsonPath('data.external_id', 'F-1')->json('data.id');
    $this->postJson($base, ['relation' => 'son', 'name' => 'Rafi 2', 'external_id' => 'F-1'])->assertUnprocessable()->assertJsonValidationErrors('external_id');
    // Saving the member again with its own HR id is no clash; the form leaving it out keeps it
    $this->putJson("{$base}/{$id}", ['relation' => 'son', 'name' => 'Rafiul', 'external_id' => 'F-1'])->assertOk();
    $this->putJson("{$base}/{$id}", ['relation' => 'son', 'name' => 'Rafiul'])->assertOk()->assertJsonPath('data.external_id', 'F-1');
});
