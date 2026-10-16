<?php

use App\Http\Requests\Tenants\UpdateTenantSettingsRequest;
use App\Services\Appointments\AppointmentSettingsRules;
use App\Services\Notifications\NotificationSettingsRules;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
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
            'per_page' => ['প্রতি পাতায় সারি ১ থেকে ২০০০-এর মধ্যে হতে হবে।'],
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

it('writes the numbers in a message in the reader\'s digits, checking with the real numbers and leaving names alone', function () {
    $errors = function (string $locale): array {
        app()->setLocale($locale);

        return Validator::make(
            ['line_2' => 7, 'pin' => '12', 'items' => ['a'], 'low' => 3, 'high' => 12345, 'minutes' => 240, 'price' => 1.5],
            ['line_2' => 'integer|between:10,2000', 'pin' => 'digits:4', 'items' => 'array|min:2', 'low' => 'integer|gt:high', 'high' => 'integer|max:9999', 'minutes' => 'integer|between:5,240', 'price' => 'numeric|multiple_of:0.25|gte:1.5'],
            [],
            ['line_2' => 'Line 2', 'pin' => 'PIN', 'items' => 'Items', 'low' => 'Low', 'high' => 'High'],
        )->errors()->toArray();
    };

    expect($errors('bn'))->toBe([
        'line_2' => ['Line 2 ১০ থেকে ২০০০-এর মধ্যে হতে হবে।'],
        'pin' => ['PIN ৪ অঙ্কের হতে হবে।'],
        'items' => ['Items-এ কমপক্ষে ২ টি আইটেম থাকতে হবে।'],
        'low' => ['Low ১২৩৪৫-এর বেশি হতে হবে।'],
        'high' => ['High ৯৯৯৯-এর বেশি হতে পারবে না।'],
    ])->and($errors('en'))->toBe([
        'line_2' => ['The Line 2 field must be between 10 and 2000.'],
        'pin' => ['The PIN field must be 4 digits.'],
        'items' => ['The Items field must have at least 2 items.'],
        'low' => ['The Low field must be greater than 12345.'],
        'high' => ['The High field must not be greater than 9999.'],
    ]);
});

it('answers an appointments setting out of range in Bangla digits', function () {
    [$tenant, $owner] = tenantWithOwner();
    $owner->update(['locale' => 'bn']);
    Sanctum::actingAs($owner);

    $this->putJson('/api/v1/appointments/settings', ['appointments' => ['slot_minutes' => 1]])
        ->assertUnprocessable()
        ->assertJsonPath('errors', ['appointments.slot_minutes' => ['সময়ের ঘরের মিনিট ৫ থেকে ২৪০-এর মধ্যে হতে হবে।']]);
});

it('names every field of the organization\'s settings in both languages, as the pages label them', function () {
    $fields = array_keys([
        ...(new UpdateTenantSettingsRequest)->rules(),
        ...NotificationSettingsRules::rules(partial: true),
        ...AppointmentSettingsRules::rules(),
    ]);

    foreach (['en', 'bn'] as $language) {
        app()->setLocale($language);
        $validator = Validator::make([], []);
        // Laravel's own fallback for a field without a name: its key, dots and all ("notifications.log days")
        $unnamed = array_values(array_filter($fields, function (string $field) use ($validator, $language): bool {
            $name = $validator->getDisplayableAttribute(str_replace('*', '0', $field));

            return str_contains($name, '.') || ($language === 'bn' && $name === str_replace('_', ' ', $field));
        }));

        expect($unnamed)->toBe([], "fields without a {$language} name");
    }
});
