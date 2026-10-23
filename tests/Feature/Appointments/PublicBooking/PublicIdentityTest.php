<?php

use App\Enums\NotificationStatus;
use App\Models\FamilyMember;
use App\Models\NotificationMessage;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;

/*
| Proving who one is on the public booking link (5.6b): the employee number, then a one-time code sent to the
| record's e-mail and phone (whatever the notification settings), the date of birth or the national ID number —
| within the organization's minutes and tries; the same answer whether or not the number is known; a session that
| each use renews and that ends; nothing of one organization's in another's.
*/

beforeEach(function () {
    Queue::fake();
    [$this->tenant, $this->owner] = tenantWithOwner();
    $this->settings = fn (array $verification = [], array $more = []) => $this->tenant->forceFill(['settings' => ['appointments' => [
        'enabled' => true, 'booking' => ['public'], 'verification' => ['method' => 'code', 'code_minutes' => 10, 'code_attempts' => 5, ...$verification], ...$more,
    ]]])->save();
    ($this->settings)();
    $in = fn (Closure $callback) => app(TenantContext::class)->run($this->tenant, $callback);
    $this->jane = cardholder($this->tenant, ['full_name' => 'Jane Doe', 'employee_no' => 'E-100', 'email' => 'jane@example.com', 'personal_phone' => '+8801711000000',
        'date_of_birth' => '1990-05-01', 'national_id' => '1990 1234-5678', 'status' => 'active']);
    $this->son = $in(fn () => FamilyMember::factory()->create(['tenant_id' => $this->tenant->id, 'cardholder_id' => $this->jane->id, 'name' => 'Sam Doe', 'relation' => 'son']));
    cardholder($this->tenant, ['full_name' => 'John Roe', 'employee_no' => 'E-200', 'email' => 'john@example.com', 'status' => 'inactive']);
    $this->url = '/api/v1/public/acme/booking';
    $this->identify = fn (string $number = 'E-100') => $this->postJson("{$this->url}/identify", ['employee_no' => $number]);
    $this->codes = fn () => NotificationMessage::query()->withoutGlobalScopes()->where('event', 'booking_code')->orderBy('id')->get();
    $this->code = fn () => preg_match('/\b(\d{6})\b/', (string) ($this->codes)()->last()?->body, $match) ? $match[1] : null;
});

it('sends a code to the record\'s e-mail and phone, even with notifications off, and signs the person in for a while', function () {
    $challenge = ($this->identify)()->assertOk()->json('data');
    expect($challenge)->toMatchArray(['method' => 'code', 'channels' => ['mail', 'sms'], 'expires_in' => 600])
        ->and(strlen($challenge['challenge']))->toBe(40)
        ->and(($this->codes)()->map(fn ($m) => [$m->channel, $m->address, $m->status])->all())->toBe([['mail', 'jane@example.com', NotificationStatus::Queued], ['sms', '+8801711000000', NotificationStatus::Queued]])
        // In the organization's message languages (Bangla by default)
        ->and(($this->codes)()->first()->subject)->toBe('Acme: আপনার বুকিংয়ের কোড '.($this->code)());

    $this->postJson("{$this->url}/verify", ['challenge' => $challenge['challenge'], 'code' => '000000'])->assertUnprocessable()
        ->assertJsonPath('errors.code.0', 'That is not right. 4 tries left.');
    // Typed in Bangla digits too
    $bangla = strtr(($this->code)(), ['0' => '০', '1' => '১', '2' => '২', '3' => '৩', '4' => '৪', '5' => '৫', '6' => '৬', '7' => '৭', '8' => '৮', '9' => '৯']);
    $session = $this->postJson("{$this->url}/verify", ['challenge' => $challenge['challenge'], 'code' => " {$bangla} "])->assertOk()->json('data');
    expect($session['person'])->toBe(['name' => 'Jane Doe', 'employee_no' => 'E-100', 'family_members' => [['id' => $this->son->public_id, 'name' => 'Sam Doe', 'relation' => 'son']]])
        ->and($session['expires_in'])->toBe(1800);
    // Used once
    $this->postJson("{$this->url}/verify", ['challenge' => $challenge['challenge'], 'code' => ($this->code)()])->assertUnprocessable()->assertJsonValidationErrors('challenge');

    $headers = ['X-Booking-Session' => $session['token']];
    $this->getJson("{$this->url}/me", $headers)->assertOk()->assertJsonPath('data.person.name', 'Jane Doe');
    $this->getJson("{$this->url}/me")->assertUnauthorized()->assertJsonPath('message', 'Your session has ended. Prove who you are again.');
    // Each use renews it; unused, it ends
    $this->travel(20)->minutes();
    $this->getJson("{$this->url}/me", $headers)->assertOk();
    $this->travel(20)->minutes();
    $this->getJson("{$this->url}/me", $headers)->assertOk();
    $this->travel(31)->minutes();
    $this->getJson("{$this->url}/me", $headers)->assertUnauthorized();
});

it('signs out, and keeps a session to its own organization', function () {
    $challenge = ($this->identify)()->json('data.challenge');
    $token = $this->postJson("{$this->url}/verify", ['challenge' => $challenge, 'code' => ($this->code)()])->json('data.token');

    [$other] = tenantWithOwner('globex', 'owner@globex.test');
    $other->forceFill(['settings' => ['appointments' => ['enabled' => true, 'booking' => ['public']]]])->save();
    $this->getJson('/api/v1/public/globex/booking/me', ['X-Booking-Session' => $token])->assertUnauthorized();

    $this->postJson("{$this->url}/sign-out", [], ['X-Booking-Session' => $token])->assertNoContent();
    $this->getJson("{$this->url}/me", ['X-Booking-Session' => $token])->assertUnauthorized();
});

it('answers the same whether the number is unknown, inactive or known, and lets no answer pass for the first two', function () {
    $known = ($this->identify)()->json('data');
    foreach (['E-999', 'E-200'] as $number) {
        $answer = ($this->identify)($number)->assertOk()->json('data');
        expect(array_keys($answer))->toBe(array_keys($known))
            ->and(collect($answer)->except('challenge')->all())->toBe(collect($known)->except('challenge')->all());
        $this->postJson("{$this->url}/verify", ['challenge' => $answer['challenge'], 'code' => ($this->code)()])->assertUnprocessable()->assertJsonValidationErrors('code');
    }
    // Only the known, active person got a code
    expect(($this->codes)()->pluck('address')->all())->toBe(['jane@example.com', '+8801711000000']);
});

it('keeps to the organization\'s tries and minutes', function () {
    ($this->settings)(['code_attempts' => 2, 'code_minutes' => 5]);
    $challenge = ($this->identify)()->json('data.challenge');
    $this->postJson("{$this->url}/verify", ['challenge' => $challenge, 'code' => '000000'])->assertJsonPath('errors.code.0', 'That is not right. 1 try left.');
    $this->postJson("{$this->url}/verify", ['challenge' => $challenge, 'code' => '000000'])->assertJsonPath('errors.challenge.0', 'No tries left. Enter your employee number again.');
    $this->postJson("{$this->url}/verify", ['challenge' => $challenge, 'code' => ($this->code)()])->assertJsonValidationErrors('challenge');

    $late = ($this->identify)()->json('data.challenge');
    $this->travel(6)->minutes();
    $this->postJson("{$this->url}/verify", ['challenge' => $late, 'code' => ($this->code)()], ['Accept-Language' => 'bn'])
        ->assertJsonPath('errors.challenge.0', 'এই যাচাইয়ের সময় শেষ। আবার কর্মী নম্বর দিন।');
});

it('proves a person by date of birth or national ID number as the organization chooses, sending nothing', function () {
    ($this->settings)(['method' => 'date_of_birth']);
    $challenge = ($this->identify)()->assertJsonPath('data.method', 'date_of_birth')->json('data.challenge');
    $this->postJson("{$this->url}/verify", ['challenge' => $challenge, 'date_of_birth' => '1990-05-02'])->assertJsonValidationErrors('date_of_birth');
    $this->postJson("{$this->url}/verify", ['challenge' => $challenge, 'date_of_birth' => '1990-05-01'])->assertOk();

    ($this->settings)(['method' => 'national_id']);
    $challenge = ($this->identify)()->json('data.challenge');
    // Typed in Bangla digits and without the spaces and dashes on record
    $this->postJson("{$this->url}/verify", ['challenge' => $challenge, 'national_id' => '১৯৯০১২৩৪৫৬৭৮'])->assertOk();
    expect(($this->codes)())->toHaveCount(0);
});

it('lets a number be checked only a few times an hour', function () {
    config(['appointments.public.identify_per_hour' => 2]);
    ($this->identify)()->assertOk();
    ($this->identify)(' e-100 ')->assertOk();
    ($this->identify)('E-100')->assertUnprocessable()->assertJsonPath('errors.employee_no.0', 'Too many tries for this employee number. Try again in 60 minutes.');
    ($this->identify)('E-200')->assertOk();
});

it('leaves out family members where the organization books for the cardholder only, and never sends a code again by hand', function () {
    ($this->settings)([], ['family_members' => false]);
    $challenge = ($this->identify)()->json('data.challenge');
    $this->postJson("{$this->url}/verify", ['challenge' => $challenge, 'code' => ($this->code)()])->assertJsonPath('data.person.family_members', []);

    $message = ($this->codes)()->first();
    $message->forceFill(['status' => NotificationStatus::Failed])->save();
    Sanctum::actingAs($this->owner);
    $this->postJson("/api/v1/notifications/messages/{$message->public_id}/resend")->assertUnprocessable()
        ->assertJsonPath('errors.message.0', 'A booking code is not sent again: the person asks for a new one on the booking page.');
    expect(collect($this->getJson('/api/v1/notifications/messages')->json('meta.events'))->firstWhere('key', 'booking_code')['name'])->toBe('Booking code');
});
