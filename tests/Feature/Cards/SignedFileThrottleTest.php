<?php

use App\Models\CardholderFile;
use App\Services\Cardholders\CardholderFileService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

it('loads every image of a full print batch from one computer without running into the rate limit', function () {
    Storage::fake(config('cardholder_files.disk'));
    [$tenant, $owner] = tenantWithOwner();
    $holder = cardholder($tenant);
    $file = app(TenantContext::class)->run($tenant, fn (): CardholderFile => app(CardholderFileService::class)->upload($holder, 'photo', UploadedFile::fake()->image('p.png', 10, 10), null, $owner));
    $url = app(CardholderFileService::class)->temporaryUrl($file);

    // A batch of max_batch cards loads a photo, two signatures and biometric images per card
    $needed = (int) config('cards.print.max_batch') * 4;
    for ($i = 1; $i <= $needed; $i++) {
        $this->get($url)->assertOk();
    }
});

it('still limits how many signed files one computer may load per minute', function () {
    Storage::fake(config('cardholder_files.disk'));
    config(['cards.print.files_per_minute' => 3]);
    [$tenant, $owner] = tenantWithOwner();
    $file = app(TenantContext::class)->run($tenant, fn (): CardholderFile => app(CardholderFileService::class)->upload(cardholder($tenant), 'photo', UploadedFile::fake()->image('p.png', 10, 10), null, $owner));
    $url = app(CardholderFileService::class)->temporaryUrl($file);

    foreach ([200, 200, 200, 429] as $status) {
        $this->get($url)->assertStatus($status);
    }
});
