<?php

use App\Enums\BiometricStatus;
use App\Jobs\RefreshBiometricStatuses;
use App\Models\Cardholder;
use App\Models\FamilyMember;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    Storage::fake(config('biometrics.disk'));
    [$this->tenant, $this->owner] = tenantWithOwner();
    $this->fingerprint = ['mode' => 'required', 'count' => 2, 'positions' => ['right_thumb', 'left_thumb'], 'substitutes' => '0', 'min_quality' => 0, 'max_attempts' => 3, 'drivers' => ['upload']];
    /** Change the biometric settings the way the Organization page does. */
    $this->settings = function (array $biometrics): void {
        $this->post('/api/v1/settings', ['name' => 'Acme', 'timezone' => 'UTC', 'locale' => 'en', 'primary_color' => '#112233', 'biometrics' => $biometrics], ['Accept' => 'application/json'])->assertOk();
        $this->owner->unsetRelation('tenant');
    };
    $this->png = 'data:image/png;base64,'.base64_encode((function () {
        ob_start();
        imagepng(imagecreatetruecolor(20, 20));

        return (string) ob_get_clean();
    })());
    $this->member = fn (Cardholder $holder) => app(TenantContext::class)->run($this->tenant, fn () => FamilyMember::factory()->create(['tenant_id' => $this->tenant->id, 'cardholder_id' => $holder->id]));
    $this->statusOf = fn (Cardholder|FamilyMember $person) => $person->fresh()->biometric_status;
    Sanctum::actingAs($this->owner);
});

it('starts a new person with the status the organization\'s settings give, however they are added', function () {
    expect(($this->statusOf)(cardholder($this->tenant)))->toBe(BiometricStatus::NotRequired);

    ($this->settings)(['enabled' => '1', 'modalities' => ['fingerprint' => $this->fingerprint]]);

    $holder = cardholder($this->tenant);
    expect(($this->statusOf)($holder))->toBe(BiometricStatus::Incomplete)
        // Family members are not captured until the organization says so
        ->and(($this->statusOf)(($this->member)($holder)))->toBe(BiometricStatus::NotRequired);
});

it('follows captures, positions marked unavailable and deletions', function () {
    ($this->settings)(['enabled' => '1', 'modalities' => ['fingerprint' => $this->fingerprint]]);
    $holder = cardholder($this->tenant);
    $url = "/api/v1/cardholders/{$holder->public_id}/biometrics";
    $capture = fn (string $position) => $this->postJson($url, ['modality' => 'fingerprint', 'position' => $position, 'driver' => 'upload', 'image_base64' => $this->png, 'image_format' => 'png'])->assertCreated();

    $capture('right_thumb');
    expect(($this->statusOf)($holder))->toBe(BiometricStatus::Incomplete);

    $left = $capture('left_thumb')->json('data.id');
    expect(($this->statusOf)($holder))->toBe(BiometricStatus::Complete);

    // A missing thumb: complete, but with an exception
    $this->postJson("{$url}/unavailable", ['modality' => 'fingerprint', 'position' => 'left_thumb', 'reason' => 'missing'])->assertCreated();
    expect(($this->statusOf)($holder))->toBe(BiometricStatus::Exceptions);

    $mark = collect($this->getJson($url)->json('data'))->firstWhere('position', 'left_thumb');
    expect($mark['status'])->toBe('unavailable')->and($mark['id'])->not->toBe($left);
    $this->deleteJson("{$url}/{$mark['id']}")->assertNoContent();
    expect(($this->statusOf)($holder))->toBe(BiometricStatus::Incomplete);
});

it('works out everybody again when the settings change, family members included', function () {
    $holder = cardholder($this->tenant);
    $member = ($this->member)($holder);

    ($this->settings)(['enabled' => '1', 'modalities' => ['fingerprint' => $this->fingerprint]]);
    expect(($this->statusOf)($holder))->toBe(BiometricStatus::Incomplete)->and(($this->statusOf)($member))->toBe(BiometricStatus::NotRequired);

    ($this->settings)(['enabled' => '1', 'family_members' => '1', 'modalities' => ['fingerprint' => $this->fingerprint]]);
    expect(($this->statusOf)($member))->toBe(BiometricStatus::Incomplete);

    // Optional capture never holds anybody up
    ($this->settings)(['enabled' => '1', 'family_members' => '1', 'modalities' => ['fingerprint' => [...$this->fingerprint, 'mode' => 'optional']]]);
    expect(($this->statusOf)($holder))->toBe(BiometricStatus::NotRequired);

    ($this->settings)(['enabled' => '0']);
    expect(($this->statusOf)($member))->toBe(BiometricStatus::NotRequired);
});

it('works everybody out again only when a change can alter their status', function () {
    ($this->settings)(['enabled' => '1', 'modalities' => ['fingerprint' => $this->fingerprint]]);
    Queue::fake();

    // When it is required and whether it prints change no status
    ($this->settings)(['enabled' => '1', 'required_before' => ['issue'], 'print_on_card' => '1', 'modalities' => ['fingerprint' => $this->fingerprint]]);
    Queue::assertNotPushed(RefreshBiometricStatuses::class);

    ($this->settings)(['enabled' => '1', 'required_before' => ['issue'], 'modalities' => ['fingerprint' => [...$this->fingerprint, 'count' => 1]]]);
    Queue::assertPushed(RefreshBiometricStatuses::class, fn (RefreshBiometricStatuses $job) => $job->tenantId === $this->tenant->id);
});

it('is not an edit of the person: no audit entry and the same updated_at', function () {
    $holder = cardholder($this->tenant);
    $updatedAt = $holder->fresh()->updated_at;
    $entries = DB::table('activity_log')->where('subject_type', Cardholder::class)->where('subject_id', $holder->id)->count();
    $this->travel(1)->minutes();

    ($this->settings)(['enabled' => '1', 'modalities' => ['fingerprint' => $this->fingerprint]]);

    expect($holder->fresh()->updated_at->equalTo($updatedAt))->toBeTrue()
        ->and(DB::table('activity_log')->where('subject_type', Cardholder::class)->where('subject_id', $holder->id)->count())->toBe($entries);
});

it('filters the cardholder list by biometric status, for those who may see biometrics', function () {
    ($this->settings)(['enabled' => '1', 'modalities' => ['fingerprint' => [...$this->fingerprint, 'count' => 1, 'positions' => ['right_thumb']]]]);
    $done = cardholder($this->tenant, ['full_name' => 'Done Person']);
    $open = cardholder($this->tenant, ['full_name' => 'Open Person']);
    $this->postJson("/api/v1/cardholders/{$done->public_id}/biometrics", ['modality' => 'fingerprint', 'position' => 'right_thumb', 'driver' => 'upload', 'image_base64' => $this->png, 'image_format' => 'png'])->assertCreated();

    $this->getJson('/api/v1/cardholders?filter[biometrics]=incomplete')->assertOk()
        ->assertJsonCount(1, 'data')->assertJsonPath('data.0.full_name', 'Open Person')->assertJsonPath('data.0.biometric_status', 'incomplete');
    $this->getJson('/api/v1/cardholders?filter[biometrics]=complete')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.full_name', 'Done Person');
    $this->getJson('/api/v1/cardholders?filter[biometrics]=sometimes')->assertJsonValidationErrors('filter.biometrics');

    // Without biometrics.view: no status, and the filter does nothing
    $clerk = tenantUser($this->tenant, 'Operator');
    $clerk->roles->first()->revokePermissionTo(['biometrics.view', 'biometrics.enroll', 'biometrics.delete']);
    Sanctum::actingAs($clerk->fresh());
    $response = $this->getJson('/api/v1/cardholders?filter[biometrics]=incomplete')->assertOk()->assertJsonCount(2, 'data');
    expect($response->json('data.0'))->not->toHaveKey('biometric_status');
    expect($open->fresh()->biometric_status)->toBe(BiometricStatus::Incomplete);
});

it('works out every status again from the command line', function () {
    ($this->settings)(['enabled' => '1', 'modalities' => ['fingerprint' => $this->fingerprint]]);
    $holder = cardholder($this->tenant);
    DB::table('cardholders')->where('id', $holder->id)->update(['biometric_status' => 'complete']);

    $this->artisan('app:biometric-status', ['tenant' => 'acme'])->expectsOutputToContain('acme: 1 people checked')->assertSuccessful();
    expect(($this->statusOf)($holder))->toBe(BiometricStatus::Incomplete);

    $this->artisan('app:biometric-status', ['tenant' => 'nobody'])->assertFailed();
});
