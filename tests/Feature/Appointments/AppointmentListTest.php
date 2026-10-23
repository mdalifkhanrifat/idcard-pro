<?php

use App\Enums\AppointmentSource;
use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\AppointmentLocation;
use App\Models\Cardholder;
use App\Models\FamilyMember;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Laravel\Sanctum\Sanctum;

/*
| The appointments list and an appointment's details (5.4c): from the organization's today on (Dhaka's day, not
| UTC's), by day and time; every filter; names in the reader's language, the kinds of visit since removed too;
| who may see them, also while the module is off; another organization's out of reach. Five bookings:
|   1 — 15 Oct (past), Head office, a whole day, confirmed, Rahim
|   2 — 16 Oct 09:00, Head office, waiting for approval, Rahim, through the public link
|   3 — 17 Oct 10:00, Branch, card renewal, cancelled, Rahim's daughter Ayesha
|   4 — 17 Oct 09:00, Head office, a kind since removed, came in, Karim (EMP-77)
|   5 — 20 Oct, Head office, a whole day, done, Karim, from another system
*/

beforeEach(function () {
    $this->withoutMiddleware(ThrottleRequests::class);
    [$this->tenant, $this->owner] = tenantWithOwner();
    $this->tenant->forceFill(['timezone' => 'Asia/Dhaka', 'settings' => ['appointments' => ['enabled' => true, 'services' => ['vehicle_pass' => ['name' => ['en' => 'Vehicle pass', 'bn' => 'গাড়ির পাস']]]]]])->save();
    $this->owner->unsetRelation('tenant');
    $this->inTenant = fn (Closure $callback) => app(TenantContext::class)->run($this->tenant, $callback);

    $this->travelTo(now()->parse('2026-10-14 08:00:00'));
    ($this->inTenant)(function () {
        $tenant = ['tenant_id' => $this->tenant->id];
        $this->head = AppointmentLocation::factory()->create([...$tenant, 'name' => 'Head office']);
        $this->branch = AppointmentLocation::factory()->create([...$tenant, 'name' => 'Branch']);
        $this->rahim = Cardholder::factory()->create([...$tenant, 'full_name' => 'Rahim Uddin', 'employee_no' => 'EMP-11']);
        $this->karim = Cardholder::factory()->create([...$tenant, 'full_name' => 'Karim Hasan', 'full_name_local' => 'করিম হাসান', 'employee_no' => 'EMP-77']);
        $this->ayesha = FamilyMember::factory()->create([...$tenant, 'cardholder_id' => $this->rahim->id, 'name' => 'Ayesha Rahim']);
        $book = function (int $n, array $values, int $minutesLater) use ($tenant): Appointment {
            $this->travel($minutesLater)->minutes();

            return Appointment::factory()->create([...$tenant, 'number' => sprintf('A-26-%05d', $n), 'location_id' => $this->head->id, 'cardholder_id' => $this->rahim->id, 'booked_by_id' => $this->owner->id, ...$values]);
        };
        $this->one = $book(1, ['date' => '2026-10-15'], 0);
        $this->two = $book(2, ['date' => '2026-10-16', 'starts_at' => '2026-10-16 03:00:00', 'ends_at' => '2026-10-16 03:15:00', 'status' => AppointmentStatus::Pending, 'source' => AppointmentSource::Public, 'booked_by_id' => null], 5);
        $this->three = $book(3, ['date' => '2026-10-17', 'starts_at' => '2026-10-17 04:00:00', 'ends_at' => '2026-10-17 04:15:00', 'location_id' => $this->branch->id, 'service' => 'renewal', 'family_member_id' => $this->ayesha->id, 'status' => AppointmentStatus::Cancelled, 'cancel_reason' => 'Travelling'], 5);
        $this->four = $book(4, ['date' => '2026-10-17', 'starts_at' => '2026-10-17 03:00:00', 'ends_at' => '2026-10-17 03:15:00', 'service' => 'old_kind', 'cardholder_id' => $this->karim->id, 'status' => AppointmentStatus::CheckedIn], 5);
        $this->five = $book(5, ['date' => '2026-10-20', 'cardholder_id' => $this->karim->id, 'status' => AppointmentStatus::Done, 'source' => AppointmentSource::Api], 5);
    });
    $this->numbers = fn (string $query = '', array $headers = []) => collect($this->getJson('/api/v1/appointments?'.$query, $headers)->assertOk()->json('data'))
        ->map(fn (array $row) => (int) substr($row['number'], -1))->all();
});

it('lists from the organization\'s today on, by day and time, on both sides of Dhaka\'s midnight', function () {
    Sanctum::actingAs($this->owner);

    // 23:30 on 16 October in Dhaka
    $this->travelTo(now()->parse('2026-10-16 17:30:00'));
    expect(($this->numbers)())->toBe([2, 4, 3, 5]);

    // 00:30 on 17 October in Dhaka — still the 16th in UTC
    $this->travelTo(now()->parse('2026-10-16 18:30:00'));
    expect(($this->numbers)())->toBe([4, 3, 5])
        ->and(($this->numbers)('filter[past]=1'))->toBe([1, 2, 4, 3, 5])
        ->and(($this->numbers)('filter[from]=2026-10-16&filter[to]=2026-10-17'))->toBe([2, 4, 3])
        // A range takes the place of "from today on"
        ->and(($this->numbers)('filter[to]=2026-10-16'))->toBe([1, 2])
        ->and(($this->numbers)('filter[from]=2026-10-17&filter[to]=2026-10-17'))->toBe([4, 3]);

    // Other orders, and pages
    expect(($this->numbers)('filter[past]=1&sort=-date'))->toBe([5, 3, 4, 2, 1])
        ->and(($this->numbers)('filter[past]=1&sort=-number'))->toBe([5, 4, 3, 2, 1])
        ->and(($this->numbers)('filter[past]=1&sort=-created_at'))->toBe([5, 4, 3, 2, 1])
        ->and(($this->numbers)('filter[past]=1&sort=created_at'))->toBe([1, 2, 3, 4, 5]);
    $page = $this->getJson('/api/v1/appointments?filter[past]=1&per_page=10&page=1')->assertOk();
    expect($page->json('meta.total'))->toBe(5)->and($page->json('meta.per_page'))->toBe(10);
    expect(count($this->getJson('/api/v1/appointments?filter[past]=1&per_page=10000')->assertUnprocessable()->json('errors')))->toBe(1);
});

it('filters by place, status, kind of visit, source, person and search', function () {
    Sanctum::actingAs($this->owner);
    $this->travelTo(now()->parse('2026-10-16 03:00:00'));
    $past = fn (string $query) => ($this->numbers)("filter[past]=1&{$query}");

    expect($past("filter[location]={$this->branch->public_id}"))->toBe([3])
        ->and($past('filter[location]=01aaaaaaaaaaaaaaaaaaaaaaaa'))->toBe([])
        ->and($past('filter[status]=cancelled'))->toBe([3])
        ->and($past('filter[status]=pending,checked_in'))->toBe([2, 4])
        ->and($past('filter[status][]=done&filter[status][]=confirmed'))->toBe([1, 5])
        ->and($past('filter[service]=renewal'))->toBe([3])
        // A kind of visit since removed from the settings still finds its bookings
        ->and($past('filter[service]=old_kind'))->toBe([4])
        ->and($past('filter[source]=public'))->toBe([2])
        ->and($past('filter[source]=api'))->toBe([5])
        // A cardholder's: their own and their family members'
        ->and($past("filter[cardholder]={$this->rahim->public_id}"))->toBe([1, 2, 3])
        ->and($past("filter[family_member]={$this->ayesha->public_id}"))->toBe([3])
        ->and($past('filter[search]=Karim'))->toBe([4, 5])
        ->and($past('filter[search]=করিম'))->toBe([4, 5])
        ->and($past('filter[search]=EMP-77'))->toBe([4, 5])
        ->and($past('filter[search]=Ayesha'))->toBe([3])
        ->and($past('filter[search]=A-26-00002'))->toBe([2])
        // Filters together
        ->and($past("filter[cardholder]={$this->rahim->public_id}&filter[status]=pending,cancelled&filter[from]=2026-10-16"))->toBe([2, 3]);
});

it('finds one appointment by its number exactly, whatever its day and however it is written', function () {
    Sanctum::actingAs($this->owner);
    // 16 October: number 1 (the 15th) is past, yet found by its number
    $this->travelTo(now()->parse('2026-10-16 08:00:00'));

    expect(($this->numbers)('filter[number]=A-26-00001'))->toBe([1])
        ->and(($this->numbers)('filter[number]=a-26-00003'))->toBe([3])
        ->and(($this->numbers)('filter[number]='.urlencode(' A-26-00004 ')))->toBe([4])
        // Only the whole number: a part of it is not one
        ->and(($this->numbers)('filter[number]=A-26-0000'))->toBe([])
        // With other criteria as usual
        ->and(($this->numbers)('filter[number]=A-26-00003&filter[status]=pending'))->toBe([]);
    $this->getJson('/api/v1/appointments?filter[number]='.str_repeat('A', 41), ['Accept-Language' => 'bn'])->assertUnprocessable()
        ->assertJsonPath('errors', fn (array $errors) => str_contains($errors['filter.number'][0], 'নম্বর') && str_contains($errors['filter.number'][0], '৪০'));
});

it('answers an appointment\'s details and names everything in the reader\'s language', function () {
    Sanctum::actingAs($this->owner);
    $this->travelTo(now()->parse('2026-10-16 03:00:00'));
    ($this->inTenant)(fn () => $this->three->forceFill(['status_changed_at' => now(), 'status_changed_by_id' => $this->owner->id])->save());

    $details = $this->getJson("/api/v1/appointments/{$this->three->public_id}")->assertOk()->json('data');
    expect($details)->toMatchArray([
        'number' => 'A-26-00003', 'date' => '2026-10-17', 'starts_at' => '2026-10-17T10:00:00+06:00', 'ends_at' => '2026-10-17T10:15:00+06:00',
        'status' => ['key' => 'cancelled', 'name' => 'Cancelled', 'open' => false], 'source' => ['key' => 'staff', 'name' => 'Staff'],
        'service' => ['key' => 'renewal', 'name' => 'Card renewal'], 'cancel_reason' => 'Travelling',
        'location' => ['id' => $this->branch->public_id, 'name' => 'Branch', 'name_local' => $this->branch->name_local],
        'family_member' => ['id' => $this->ayesha->public_id, 'name' => 'Ayesha Rahim', 'relation' => $this->ayesha->relation->value],
        'booked_by' => ['id' => $this->owner->public_id, 'name' => $this->owner->name],
        'status_changed_by' => ['id' => $this->owner->public_id, 'name' => $this->owner->name],
    ])->and($details['cardholder']['id'])->toBe($this->rahim->public_id);

    // A whole day's booking has no times; a kind since removed keeps its key as its name
    $list = collect($this->getJson('/api/v1/appointments?filter[past]=1')->json('data'))->keyBy('number');
    expect($list['A-26-00005'])->toMatchArray(['starts_at' => null, 'ends_at' => null, 'booked_by' => ['id' => $this->owner->public_id, 'name' => $this->owner->name]])
        ->and($list['A-26-00004']['service'])->toBe(['key' => 'old_kind', 'name' => 'old_kind'])
        ->and($list['A-26-00002']['booked_by'])->toBeNull()
        // Still to be served while waiting, confirmed or come in; finished once done
        ->and($list['A-26-00004']['status']['open'])->toBeTrue()
        ->and($list['A-26-00005']['status']['open'])->toBeFalse()
        // The list leaves out who changed the status (the details have it)
        ->and($list['A-26-00003'])->not->toHaveKey('status_changed_by');

    // meta names what to filter by: every status and source, the organization's kinds (its own too) and removed ones
    $meta = $this->getJson('/api/v1/appointments', ['Accept-Language' => 'bn'])->json('meta');
    expect(array_column($meta['statuses'], 'key'))->toBe(array_column(AppointmentStatus::cases(), 'value'))
        ->and($meta['statuses'][0])->toBe(['key' => 'pending', 'name' => 'অনুমোদনের অপেক্ষায়'])
        ->and(array_column($meta['sources'], 'name'))->toBe(['কর্মী', 'পাবলিক লিংক', 'অন্য সিস্টেম'])
        ->and(array_column($meta['services'], 'key'))->toBe([...array_keys(config('appointments.services')), 'vehicle_pass', 'old_kind'])
        ->and(collect($meta['services'])->firstWhere('key', 'vehicle_pass')['name'])->toBe('গাড়ির পাস')
        ->and(collect($meta['services'])->firstWhere('key', 'old_kind')['name'])->toBe('old_kind');
    expect($this->getJson("/api/v1/appointments/{$this->three->public_id}", ['Accept-Language' => 'bn'])->json('data.status.name'))->toBe('বাতিল');
});

it('lets those who see appointments read them — also while the module is off — and keeps other organizations out', function () {
    $this->travelTo(now()->parse('2026-10-16 03:00:00'));
    $this->tenant->forceFill(['settings' => ['appointments' => ['enabled' => false]]])->save();
    $without = ($this->inTenant)(fn () => User::factory()->create(['tenant_id' => $this->tenant->id]));

    foreach (['Owner' => $this->owner, 'Administrator' => tenantUser($this->tenant, 'Administrator'), 'Operator' => tenantUser($this->tenant, 'Operator'), 'Viewer' => tenantUser($this->tenant, 'Viewer')] as $who => $user) {
        $user->unsetRelation('tenant');
        Sanctum::actingAs($user);
        expect(($this->numbers)('filter[past]=1'))->toBe([1, 2, 4, 3, 5], $who);
        $this->getJson("/api/v1/appointments/{$this->one->public_id}")->assertOk();
    }
    Sanctum::actingAs($without);
    $this->getJson('/api/v1/appointments')->assertForbidden();
    $this->getJson("/api/v1/appointments/{$this->one->public_id}")->assertForbidden();

    [, $otherOwner] = tenantWithOwner('globex', 'owner@globex.test');
    Sanctum::actingAs($otherOwner);
    expect($this->getJson('/api/v1/appointments?filter[past]=1')->assertOk()->json('data'))->toBe([])
        ->and($this->getJson("/api/v1/appointments?filter[past]=1&filter[cardholder]={$this->rahim->public_id}")->json('data'))->toBe([]);
    $this->getJson("/api/v1/appointments/{$this->one->public_id}")->assertNotFound();
});

it('refuses wrong filters in the reader\'s language', function () {
    Sanctum::actingAs($this->owner);
    $bn = ['Accept-Language' => 'bn'];

    $this->getJson('/api/v1/appointments?filter[status]=lost', $bn)->assertUnprocessable()->assertJsonValidationErrors('filter.status.0')
        ->assertJsonPath('errors', fn (array $errors) => str_contains($errors['filter.status.0'][0], 'অবস্থা'));
    $this->getJson('/api/v1/appointments?filter[from]=2026-10-20&filter[to]=2026-10-19', $bn)->assertUnprocessable()
        ->assertJsonPath('errors', fn (array $errors) => str_contains($errors['filter.to'][0], 'পর্যন্ত'));
    $this->getJson('/api/v1/appointments?filter[source]=fax', $bn)->assertUnprocessable()
        ->assertJsonPath('errors', fn (array $errors) => str_contains($errors['filter.source'][0], 'কোথা থেকে বুক'));
    $this->getJson('/api/v1/appointments?filter[service]=Bad Kind')->assertUnprocessable()->assertJsonValidationErrors('filter.service');
    $this->getJson('/api/v1/appointments?sort=name')->assertUnprocessable()->assertJsonValidationErrors('sort');
    $this->getJson('/api/v1/appointments?filter[colour]=red')->assertUnprocessable()->assertJsonValidationErrors('filter');
});
