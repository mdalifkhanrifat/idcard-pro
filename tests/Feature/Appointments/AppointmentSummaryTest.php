<?php

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\AppointmentLocation;
use App\Models\Cardholder;
use App\Support\Tenancy\TenantContext;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Laravel\Sanctum\Sanctum;

/*
| Appointments counted by day and status (5.5g — the week view): the list's own criteria over the days asked for,
| every day and every status in the answer (zero too), on the organization's days.
*/

beforeEach(function () {
    $this->withoutMiddleware(ThrottleRequests::class);
    [$this->tenant, $this->owner] = tenantWithOwner();
    $this->tenant->forceFill(['timezone' => 'Asia/Dhaka', 'settings' => ['appointments' => ['enabled' => true]]])->save();
    $this->owner->unsetRelation('tenant');
    $in = fn (Closure $callback) => app(TenantContext::class)->run($this->tenant, $callback);
    [$this->head, $this->branch, $holder] = $in(fn () => [
        AppointmentLocation::factory()->create(['tenant_id' => $this->tenant->id]),
        AppointmentLocation::factory()->create(['tenant_id' => $this->tenant->id]),
        Cardholder::factory()->create(['tenant_id' => $this->tenant->id]),
    ]);
    $n = 0;
    $book = function (string $date, AppointmentStatus $status, ?AppointmentLocation $place = null, string $service = 'new_card') use ($in, $holder, &$n) {
        $n++;

        return $in(fn () => Appointment::factory()->create(['tenant_id' => $this->tenant->id, 'number' => sprintf('A-26-%05d', $n), 'location_id' => ($place ?? $this->head)->id,
            'cardholder_id' => $holder->id, 'date' => $date, 'starts_at' => null, 'ends_at' => null, 'status' => $status, 'service' => $service]));
    };
    $book('2026-10-19', AppointmentStatus::Confirmed);
    $book('2026-10-19', AppointmentStatus::Confirmed, $this->branch);
    $book('2026-10-19', AppointmentStatus::CheckedIn, service: 'renewal');
    $book('2026-10-21', AppointmentStatus::Cancelled);
    // Outside the week asked for
    $book('2026-10-18', AppointmentStatus::Pending);
    $book('2026-10-26', AppointmentStatus::Pending);
    Sanctum::actingAs($this->owner);
});

it('counts every day of the range by status, zero days and statuses too', function () {
    $data = $this->getJson('/api/v1/appointments/summary?filter[from]=2026-10-19&filter[to]=2026-10-25')->assertOk()->json();

    expect(array_column($data['data']['days'], 'date'))->toBe(['2026-10-19', '2026-10-20', '2026-10-21', '2026-10-22', '2026-10-23', '2026-10-24', '2026-10-25'])
        ->and(array_column($data['data']['days'], 'total'))->toBe([3, 0, 1, 0, 0, 0, 0])
        ->and($data['data']['days'][0]['statuses'])->toBe(['pending' => 0, 'confirmed' => 2, 'checked_in' => 1, 'done' => 0, 'no_show' => 0, 'cancelled' => 0])
        ->and($data['data']['days'][2]['statuses']['cancelled'])->toBe(1)
        ->and(array_column($data['meta']['statuses'], 'key'))->toBe(array_column(AppointmentStatus::cases(), 'value'));
});

it('follows the list\'s criteria and names the days asked for wrongly', function () {
    $totals = fn (string $query) => array_column($this->getJson("/api/v1/appointments/summary?filter[from]=2026-10-19&filter[to]=2026-10-21&{$query}")->assertOk()->json('data.days'), 'total');

    expect($totals("filter[location]={$this->branch->public_id}"))->toBe([1, 0, 0])
        ->and($totals('filter[service]=renewal'))->toBe([1, 0, 0])
        ->and($totals('filter[status]=confirmed,cancelled'))->toBe([2, 0, 1]);

    $bn = ['Accept-Language' => 'bn'];
    $this->getJson('/api/v1/appointments/summary', $bn)->assertUnprocessable()->assertJsonValidationErrors(['filter']);
    $this->getJson('/api/v1/appointments/summary?filter[from]=2026-10-19', $bn)->assertUnprocessable()->assertJsonValidationErrors(['filter.to']);
    $this->getJson('/api/v1/appointments/summary?filter[from]=2026-10-19&filter[to]=2026-12-31', $bn)->assertUnprocessable()
        ->assertJsonPath('errors', fn (array $errors) => $errors['filter.to'][0] === 'একবারে সর্বোচ্চ ৬২ দিন গোনা যায়।');
    $this->getJson('/api/v1/appointments/summary?filter[from]=2026-10-19&filter[to]=2026-10-19&filter[past]=1')->assertUnprocessable()->assertJsonValidationErrors(['filter']);
});

it('is seen with appointments.view, within the organization', function () {
    Sanctum::actingAs(tenantUser($this->tenant, 'Viewer'));
    $this->getJson('/api/v1/appointments/summary?filter[from]=2026-10-19&filter[to]=2026-10-19')->assertOk()->assertJsonPath('data.days.0.total', 3);

    [, $other] = tenantWithOwner('globex', 'owner@globex.test');
    Sanctum::actingAs($other);
    $this->getJson('/api/v1/appointments/summary?filter[from]=2026-10-19&filter[to]=2026-10-19')->assertOk()->assertJsonPath('data.days.0.total', 0);
});
