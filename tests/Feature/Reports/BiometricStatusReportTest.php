<?php

use App\Enums\BiometricStatus;
use App\Models\BiometricCapture;
use App\Models\Cardholder;
use App\Models\FamilyMember;
use App\Services\Biometrics\BiometricStatusService;
use App\Services\Reports\ReportRegistry;
use App\Support\Tenancy\TenantContext;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Laravel\Sanctum\Sanctum;

/*
| The biometric status report (5.8): the cardholder list's criteria, each cardholder's status and every kind of
| biometric of the registry as a column — how it stands against the organization's settings, by the same rule as
| enrollment and the card checks. Settings: two fingerprints required (right thumb, right index; no stand-ins),
| the face optional, the iris off. 14 October 2026, 14:00 in Dhaka.
|   Ana — both fingers and the face: complete
|   Bob — the right thumb only: incomplete (right index still to take; the face optional)
|   Cid — right thumb missing (marked), right index taken: complete with exceptions
|   Dee — a right thumb taken once and replaced, nothing current: incomplete
*/

beforeEach(function () {
    $this->withoutMiddleware(ThrottleRequests::class);
    [$this->tenant, $this->owner] = tenantWithOwner();
    $this->tenant->forceFill(['timezone' => 'Asia/Dhaka', 'settings' => ['biometrics' => [
        'enabled' => true,
        'modalities' => [
            'fingerprint' => ['mode' => 'required', 'count' => 2, 'positions' => ['right_thumb', 'right_index'], 'substitutes' => false],
            'face' => ['mode' => 'optional', 'count' => 1, 'positions' => ['face']],
            'iris' => ['mode' => 'off'],
        ],
    ]]])->save();
    $this->owner->unsetRelation('tenant');
    $this->travelTo(now()->parse('2026-10-14 08:00:00'));
    $in = fn (callable $callback) => app(TenantContext::class)->run($this->tenant->fresh(), $callback);
    // A cardholder with captures [modality, position, unavailable reason|null, replaced], status kept by the service
    $person = fn (string $name, string $number, array $captures) => $in(function () use ($name, $number, $captures): Cardholder {
        $holder = cardholder($this->tenant, ['full_name' => $name, 'employee_no' => $number]);
        foreach ($captures as $at => [$modality, $position, $reason, $replaced]) {
            BiometricCapture::factory()->when($reason !== null, fn ($f) => $f->unavailable($reason))->create([
                'tenant_id' => $this->tenant->id, 'cardholder_id' => $holder->id, 'modality' => $modality, 'position' => $position,
                'captured_by' => $this->owner->id, 'captured_at' => now()->subHours(5 - $at), 'replaced_at' => $replaced ? now() : null,
            ]);
        }
        app(BiometricStatusService::class)->refresh($holder->fresh());

        return $holder;
    });
    $this->ana = $person('Ana', 'E-1', [['fingerprint', 'right_thumb', null, false], ['fingerprint', 'right_index', null, false], ['face', 'face', null, false]]);
    $this->bob = $person('Bob', 'E-2', [['fingerprint', 'right_thumb', null, false]]);
    $this->cid = $person('Cid', 'E-3', [['fingerprint', 'right_thumb', 'missing', false], ['fingerprint', 'right_index', null, false]]);
    $this->dee = $person('Dee', 'E-4', [['fingerprint', 'right_thumb', null, true]]);
    // A family member's capture never counts for the cardholder
    $in(function () {
        $son = FamilyMember::factory()->create(['tenant_id' => $this->tenant->id, 'cardholder_id' => $this->dee->id, 'name' => 'Dee Jr']);
        BiometricCapture::factory()->create(['tenant_id' => $this->tenant->id, 'cardholder_id' => $this->dee->id, 'family_member_id' => $son->id, 'position' => 'right_index']);
    });

    Sanctum::actingAs($this->owner);
    $this->rows = fn (string $query = '', string $language = 'en') => collect($this->getJson('/api/v1/reports/biometric-status?'.$query, ['Accept-Language' => $language])->assertOk()->json('data'))
        ->mapWithKeys(fn (array $row) => [$row['full_name'] => $row['values']])->all();
});

it('shows for every kind of biometric what was taken, what is still to take and what could not be taken', function () {
    $columns = 'columns[]=biometric.status&columns[]=biometric.fingerprint&columns[]=biometric.face&columns[]=biometric.iris';

    expect(($this->rows)($columns))->toBe([
        'Ana' => ['biometric.status' => 'Complete', 'biometric.fingerprint' => 'Done (2 taken, 2 needed)', 'biometric.face' => 'Done (1 taken, 1 needed)', 'biometric.iris' => 'Not captured here'],
        'Bob' => ['biometric.status' => 'Incomplete', 'biometric.fingerprint' => '1 of 2 — still to take: Right index', 'biometric.face' => 'Optional, 0 of 1 taken', 'biometric.iris' => 'Not captured here'],
        'Cid' => ['biometric.status' => 'With exceptions', 'biometric.fingerprint' => 'Done with exceptions (1 of 2) — Right thumb: Missing', 'biometric.face' => 'Optional, 0 of 1 taken', 'biometric.iris' => 'Not captured here'],
        'Dee' => ['biometric.status' => 'Incomplete', 'biometric.fingerprint' => '0 of 2 — still to take: Right thumb, Right index', 'biometric.face' => 'Optional, 0 of 1 taken', 'biometric.iris' => 'Not captured here'],
    ])->and(($this->rows)($columns, 'bn')['Bob'])->toBe([
        'biometric.status' => 'অসম্পূর্ণ', 'biometric.fingerprint' => '২-এর মধ্যে ১ — বাকি: ডান তর্জনী', 'biometric.face' => 'ঐচ্ছিক, ১-এর মধ্যে ০ নেওয়া', 'biometric.iris' => 'এখানে নেওয়া হয় না',
    ]);

    // With stand-ins allowed, more than needed may be taken: said as taken and needed, never "3 of 2"
    $this->tenant->forceFill(['settings' => ['biometrics' => [...$this->tenant->settings['biometrics'], 'modalities' => [
        ...$this->tenant->settings['biometrics']['modalities'], 'fingerprint' => ['mode' => 'required', 'count' => 1, 'positions' => ['right_thumb'], 'substitutes' => true],
    ]]]])->save();
    $this->owner->unsetRelation('tenant');
    expect(($this->rows)('columns[]=biometric.fingerprint')['Ana'])->toBe(['biometric.fingerprint' => 'Done (2 taken, 1 needed)'])
        ->and(($this->rows)('columns[]=biometric.fingerprint', 'bn')['Ana'])->toBe(['biometric.fingerprint' => 'সম্পন্ন (২টা নেওয়া, দরকার ১)']);

    // The last capture: when (the organization's clock) and by whom; none for a person with nothing current
    $last = ($this->rows)('columns[]=biometric.last_captured_at&columns[]=biometric.last_captured_by');
    expect($last['Ana'])->toBe(['biometric.last_captured_at' => '2026-10-14T11:00:00+06:00', 'biometric.last_captured_by' => 'Owner'])
        ->and($last['Dee'])->toBe(['biometric.last_captured_at' => null, 'biometric.last_captured_by' => null]);
});

it('finds people as the cardholder list does, counts each status, and shows the kinds the organization captures by default', function () {
    $names = fn (string $query) => array_keys(($this->rows)($query.'&columns[]=biometric.status'));
    expect($names('filter[biometrics]=incomplete'))->toBe(['Bob', 'Dee'])
        ->and($names('filter[search]=E-3'))->toBe(['Cid'])
        ->and($names('sort=-employee_no'))->toBe(['Dee', 'Cid', 'Bob', 'Ana']);
    $this->getJson('/api/v1/reports/biometric-status?per_page=1')->assertJsonPath('meta.totals', [
        'cardholders' => 4, 'biometric_complete' => 1, 'biometric_exceptions' => 1, 'biometric_incomplete' => 2, 'biometric_not_required' => 0,
    ]);

    $report = collect($this->getJson('/api/v1/reports', ['Accept-Language' => 'bn'])->json('data'))->keyBy('key')['biometric_status'];
    // A column per kind of the registry; by default the kinds the organization captures (not the iris)
    expect(array_keys(app(ReportRegistry::class)->get('biometric_status')->columns()))->toContain('biometric.fingerprint', 'biometric.face', 'biometric.iris')
        ->and($report['default_columns'] ?? collect($report['columns'])->where('default', true)->pluck('key')->all())->toContain('biometric.fingerprint', 'biometric.face')->not->toContain('biometric.iris')
        ->and(array_column($report['totals'], 'label'))->toBe(['কার্ডধারী', ...array_map(fn (BiometricStatus $s) => __("reports.values.biometric_status.{$s->value}", [], 'bn'), BiometricStatus::cases())])
        ->and($report['name'])->toBe('বায়োমেট্রিক অবস্থা');

    // Biometrics off: no kind is captured, so none is shown by default
    $this->tenant->forceFill(['settings' => ['biometrics' => [...$this->tenant->settings['biometrics'], 'enabled' => false]]])->save();
    $this->owner->unsetRelation('tenant');
    $off = collect($this->getJson('/api/v1/reports')->json('data'))->keyBy('key')['biometric_status'];
    expect($off['default_columns'] ?? collect($off['columns'])->where('default', true)->pluck('key')->all())->not->toContain('biometric.fingerprint', 'biometric.face');
});

it('names every kind, position and reason on the server as the pages do', function () {
    foreach (['en', 'bn'] as $language) {
        $pages = json_decode((string) file_get_contents(resource_path("js/locales/{$language}.json")), true)['biometrics'];
        foreach ((array) config('biometrics.modalities') as $modality => $definition) {
            expect(__("biometrics.modalities.{$modality}", [], $language))->toBe($pages['modalities'][$modality]);
            foreach (array_keys($definition['positions']) as $position) {
                expect(__("biometrics.positions.{$position}", [], $language))->toBe($pages['positions'][$position]);
            }
        }
        foreach ((array) config('biometrics.unavailable_reasons') as $reason) {
            expect(__("biometrics.reasons.{$reason}", [], $language))->toBe($pages['reasons'][$reason]);
        }
    }
});
