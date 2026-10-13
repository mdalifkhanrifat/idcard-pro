<?php

use Illuminate\Support\Arr;
use Laravel\Sanctum\Sanctum;

it('has a Bangla message for every validation rule and a Bangla name for every named field', function () {
    $english = require lang_path('en/validation.php');
    $bangla = require lang_path('bn/validation.php');

    $rules = fn (array $lines) => array_keys(Arr::dot(Arr::except($lines, ['custom', 'attributes'])));

    expect(array_diff($rules($english), $rules($bangla)))->toBe([])
        ->and(array_diff(array_keys($english['attributes']), array_keys($bangla['attributes'])))->toBe([]);
});

it('answers a Bangla reader in Bangla, field names included', function () {
    [$tenant, $owner] = tenantWithOwner();
    $owner->update(['locale' => 'bn']);
    Sanctum::actingAs($owner);

    $this->getJson('/api/v1/cards?filter[issued_from]=2026-01-02&filter[issued_to]=2026-01-01&per_page=0')
        ->assertJsonPath('errors', [
            'filter.issued_to' => ['ইস্যুর তারিখ (পর্যন্ত) অবশ্যই ইস্যুর তারিখ (থেকে)-এর দিন বা তার পরের তারিখ হতে হবে।'],
            'per_page' => ['প্রতি পাতায় সারি 1 থেকে 100-এর মধ্যে হতে হবে।'],
        ]);
});

it('names a cardholder\'s fields as cards and reports name them', function () {
    [$tenant, $owner] = tenantWithOwner();
    $owner->update(['locale' => 'bn']);
    Sanctum::actingAs($owner);

    $errors = $this->postJson('/api/v1/cardholders', ['email' => 'not-an-email', 'blood_group_id' => str_repeat('z', 26)])->assertUnprocessable()->json('errors');

    expect($errors['full_name'][0])->toBe('পূর্ণ নাম দিতে হবে।')
        ->and($errors['email'][0])->toBe('ইমেইল একটি সঠিক ইমেইল ঠিকানা হতে হবে।')
        ->and($errors['blood_group_id'][0])->toBe('বাছাই করা রক্তের গ্রুপ সঠিক নয়।');
});
