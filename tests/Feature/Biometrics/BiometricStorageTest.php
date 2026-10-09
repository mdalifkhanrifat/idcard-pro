<?php

use App\Models\BiometricCapture;
use App\Models\FamilyMember;
use App\Repositories\Contracts\BiometricCaptureRepositoryInterface;
use App\Services\Biometrics\BiometricVault;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    Storage::fake(config('biometrics.disk'));
    [$this->tenant, $this->owner] = tenantWithOwner();
    app(TenantContext::class)->set($this->tenant);
    $this->holder = cardholder($this->tenant);
    $this->image = "\x89PNG\r\n\x1a\nfinger-ridges-".random_bytes(64);
    /** Stores an encrypted image and template and records them as the current capture of a position. */
    $this->capture = function (string $position, ?FamilyMember $member = null): BiometricCapture {
        $vault = app(BiometricVault::class);
        $image = $vault->put($this->tenant->public_id, $this->image);
        $template = $vault->put($this->tenant->public_id, 'FMR'.random_bytes(32));

        return BiometricCapture::factory()->create([
            'tenant_id' => $this->tenant->id, 'cardholder_id' => $this->holder->id, 'family_member_id' => $member?->id,
            'position' => $position, 'disk' => $image['disk'], 'image_path' => $image['path'], 'image_format' => 'png',
            'template_path' => $template['path'], 'template_format' => 'iso-19794-2',
            'current_key' => BiometricCapture::currentKey($this->holder->id, $member?->id, 'fingerprint', $position),
        ]);
    };
});

it('stores images encrypted with the biometric key under random names, and reads them back', function () {
    $vault = app(BiometricVault::class);
    $stored = $vault->put($this->tenant->public_id, $this->image);

    expect($stored['path'])->toMatch("#^biometrics/{$this->tenant->public_id}/[0-9a-z]{26}\\.bin$#");
    $onDisk = Storage::disk($stored['disk'])->get($stored['path']);
    expect($onDisk)->not->toContain('finger-ridges')->not->toContain('PNG')
        ->and($vault->get($stored['disk'], $stored['path']))->toBe($this->image);
    // Not readable with the application key
    expect(fn () => app('encrypter')->decryptString($onDisk))->toThrow(Exception::class);
});

it('still reads data saved with a previous key after the key is changed', function () {
    $stored = app(BiometricVault::class)->put($this->tenant->public_id, $this->image);
    $old = config('biometrics.key');
    config(['biometrics.key' => 'base64:'.base64_encode(Encrypter::generateKey('aes-256-gcm')), 'biometrics.previous_keys' => [$old]]);

    expect(app(BiometricVault::class)->get($stored['disk'], $stored['path']))->toBe($this->image);
    config(['biometrics.previous_keys' => []]);
    expect(fn () => app(BiometricVault::class)->get($stored['disk'], $stored['path']))->toThrow(Exception::class);
});

it('refuses to store anything without a biometric key', function () {
    config(['biometrics.key' => null]);

    expect(fn () => app(BiometricVault::class)->put($this->tenant->public_id, $this->image))->toThrow(RuntimeException::class, 'BIOMETRIC_KEY');
    expect(Storage::disk(config('biometrics.disk'))->allFiles())->toBe([]);
});

it('keeps one current capture per position and the replaced ones as history', function () {
    $first = ($this->capture)('right_thumb');
    expect(fn () => ($this->capture)('right_thumb'))->toThrow(UniqueConstraintViolationException::class);

    $repository = app(BiometricCaptureRepositoryInterface::class);
    expect($repository->markReplaced($this->holder, null, 'fingerprint', 'right_thumb'))->toBe(1);
    $second = ($this->capture)('right_thumb');
    ($this->capture)('left_thumb');

    expect($repository->currentFor($this->holder)->pluck('id')->sort()->values()->all())->toHaveCount(2)->not->toContain($first->id)
        ->and($first->refresh()->replaced_at)->not->toBeNull()
        ->and($first->current_key)->toBeNull()
        ->and($second->refresh()->current_key)->toBe("c{$this->holder->id}:fingerprint:right_thumb");
});

it('keeps a family member\'s captures apart from the cardholder\'s', function () {
    $member = FamilyMember::factory()->create(['tenant_id' => $this->tenant->id, 'cardholder_id' => $this->holder->id]);
    ($this->capture)('right_thumb');
    ($this->capture)('right_thumb', $member);
    $repository = app(BiometricCaptureRepositoryInterface::class);

    expect($repository->currentFor($this->holder))->toHaveCount(1)
        ->and($repository->currentFor($this->holder, $member)->first()->family_member_id)->toBe($member->id);
});

it('removes the encrypted files when a family member or the cardholder is deleted', function () {
    Sanctum::actingAs($this->owner);
    $member = FamilyMember::factory()->create(['tenant_id' => $this->tenant->id, 'cardholder_id' => $this->holder->id]);
    $own = ($this->capture)('right_thumb');
    $members = ($this->capture)('left_thumb', $member);
    $disk = Storage::disk(config('biometrics.disk'));
    expect($disk->allFiles())->toHaveCount(4);

    $this->deleteJson("/api/v1/cardholders/{$this->holder->public_id}/family-members/{$member->public_id}")->assertNoContent();
    $disk->assertMissing([$members->image_path, $members->template_path]);
    $disk->assertExists([$own->image_path, $own->template_path]);

    $this->deleteJson("/api/v1/cardholders/{$this->holder->public_id}")->assertNoContent();
    expect($disk->allFiles())->toBe([])->and(BiometricCapture::query()->withoutGlobalScopes()->count())->toBe(0);
});

it('shows an organization only its own captures', function () {
    ($this->capture)('right_thumb');
    [$other] = tenantWithOwner('globex', 'owner@globex.test');

    app(TenantContext::class)->set($other);
    expect(BiometricCapture::query()->count())->toBe(0);
});

it('logs what was captured but never the files', function () {
    ($this->capture)('right_thumb');
    $logged = Activity::query()->where('log_name', 'biometrics')->latest('id')->first();

    expect($logged->attribute_changes['attributes'])->toHaveKeys(['modality', 'position', 'status', 'quality'])
        ->not->toHaveKeys(['image_path', 'template_path', 'disk', 'device', 'current_key']);
});

it('creates the biometric key once and will not silently replace it', function () {
    $dir = sys_get_temp_dir().'/biometric-key-'.uniqid();
    mkdir($dir);
    file_put_contents("{$dir}/.env", "APP_NAME=Test\nBIOMETRIC_KEY=\n");
    app()->useEnvironmentPath($dir);

    $this->artisan('app:biometric-key --show')->expectsOutputToContain('base64:')->assertSuccessful();
    expect(file_get_contents("{$dir}/.env"))->toContain("BIOMETRIC_KEY=\n");

    $this->artisan('app:biometric-key')->assertSuccessful();
    $env = file_get_contents("{$dir}/.env");
    expect($env)->toMatch('/^BIOMETRIC_KEY=base64:[A-Za-z0-9+\/]{43}=$/m');
    expect(strlen(BiometricVault::parseKey(explode('=', explode("\n", $env)[1], 2)[1])))->toBe(32);

    $this->artisan('app:biometric-key')->assertFailed();
    expect(file_get_contents("{$dir}/.env"))->toBe($env);
    $this->artisan('app:biometric-key --force')->assertSuccessful();
    expect(file_get_contents("{$dir}/.env"))->not->toBe($env);

    unlink("{$dir}/.env");
    rmdir($dir);
});
