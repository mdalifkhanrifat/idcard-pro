<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Laravel\Sanctum\Sanctum;
use Spatie\Activitylog\Models\Activity;
use Spatie\Activitylog\Models\Concerns\LogsActivity;

/*
| 6.3h: whatever people change is audited. Every audited model logs all it may be given (logFillable), less the
| machine's own fields it names (logExcept) — so a new field is audited unless someone leaves it out on purpose.
| Before, a unit moved to another department, a signatory's local name, a card's notes, a password changed left
| no trace.
*/

beforeEach(function () {
    [$this->tenant, $this->owner] = tenantWithOwner();
    Sanctum::actingAs($this->owner);
});

it('audits every field a record may be given unless the model leaves it out by name', function () {
    $listed = [];
    foreach (File::files(app_path('Models')) as $file) {
        $class = 'App\\Models\\'.$file->getFilenameWithoutExtension();
        if (in_array(LogsActivity::class, class_uses_recursive($class), true) && ! (new $class)->getActivitylogOptions()->logFillable) {
            $listed[] = class_basename($class);
        }
    }

    expect($listed)->toBe([]);
});

it('notes who changed a password and when, never the password', function () {
    // Making the owner was one row: its first password is part of it
    expect(Activity::query()->where('log_name', 'user')->pluck('event')->all())->toBe(['created']);

    // A later moment (stored to the second, so a change in the same second would not show)
    $this->travel(5)->minutes();
    $this->putJson('/api/v1/auth/password', ['current_password' => TEST_PASSWORD, 'password' => 'Brand#New9876', 'password_confirmation' => 'Brand#New9876'])
        ->assertOk();

    $row = Activity::query()->where('log_name', 'user')->where('event', 'updated')->sole();
    expect($row->causer_id)->toBe($this->owner->id)
        ->and(array_keys($row->attribute_changes['attributes']))->toBe(['password_changed_at'])
        ->and(DB::table('activity_log')->where('attribute_changes', 'like', '%$2y$%')->exists())->toBeFalse();
});

it('audits a unit moved to another department, by name', function () {
    $finance = masterItem($this->tenant, ['type' => 'department', 'name' => 'Finance']);
    $people = masterItem($this->tenant, ['type' => 'department', 'name' => 'Human Resources']);
    $unit = masterItem($this->tenant, ['type' => 'unit', 'name' => 'North Wing', 'parent_id' => $finance->id]);

    $this->putJson("/api/v1/master-data/unit/{$unit->public_id}", ['name' => 'North Wing', 'parent_id' => $people->public_id])->assertOk();

    $this->getJson('/api/v1/audit/activities?filter[log_name]=master_data&filter[event]=updated')
        ->assertOk()
        ->assertJsonPath('data.0.fields', [['key' => 'parent_id', 'label' => 'Inside', 'old' => 'Finance', 'new' => 'Human Resources']]);
});

it('notes a certificate number as changed, never its value', function () {
    $holder = cardholder($this->tenant);
    $base = "/api/v1/cardholders/{$holder->public_id}/qualifications";
    $course = $this->postJson($base, ['kind' => 'course', 'title' => 'First aid', 'subject' => 'Health', 'certificate_no' => 'FA-123'])->assertCreated()->json('data.id');
    $this->putJson("{$base}/{$course}", ['kind' => 'course', 'title' => 'First aid', 'subject' => 'Health', 'certificate_no' => 'FA-456'])->assertOk();

    $this->getJson('/api/v1/audit/activities?filter[log_name]=qualifications&filter[event]=created')
        ->assertOk()
        ->assertJsonFragment(['key' => 'subject', 'label' => 'Group / subject', 'old' => null, 'new' => 'Health'])
        ->assertJsonFragment(['key' => 'certificate_no', 'label' => 'Certificate no', 'old' => null, 'new' => '(hidden)']);
    $this->getJson('/api/v1/audit/activities?filter[log_name]=qualifications&filter[event]=updated')
        ->assertOk()
        ->assertJsonPath('data.0.fields', [['key' => 'certificate_no', 'label' => 'Certificate no', 'old' => '(hidden)', 'new' => '(hidden)']]);
    expect(DB::table('activity_log')->where('attribute_changes', 'like', '%FA-%')->exists())->toBeFalse();
});
