<?php

use App\Data\Reports\ReportQuery;
use App\Enums\AppointmentSource;
use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\AppointmentLocation;
use App\Models\FamilyMember;
use App\Services\Reports\ReportFilterSummary;
use App\Services\Reports\ReportRegistry;
use App\Support\Tenancy\TenantContext;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Laravel\Sanctum\Sanctum;

/*
| The appointments report (5.5f): the list's own criteria, so the Appointments page and a cardholder's tab download
| what they show — the default days (from today on) too; every column in both languages; totals per status named as
| the statuses are. 14 October 2026, 14:00 in Dhaka.
|   1 — 15 Oct 09:00–09:30, Head office, new card, confirmed, Jane (booked by the owner, a note)
|   2 — 16 Oct, a whole day, Branch, renewal, waiting, Jane's son Sam (public link)
|   3 — 10 Oct 10:00–10:30 (past), Head office, new card, cancelled, John (a reason; last step by the owner)
*/

beforeEach(function () {
    $this->withoutMiddleware(ThrottleRequests::class);
    [$this->tenant, $this->owner] = tenantWithOwner();
    $this->tenant->forceFill(['timezone' => 'Asia/Dhaka', 'settings' => ['appointments' => ['enabled' => true]]])->save();
    $this->owner->unsetRelation('tenant');
    $in = fn (callable $callback) => app(TenantContext::class)->run($this->tenant, $callback);
    $this->travelTo(now()->parse('2026-10-14 08:00:00'));

    $this->jane = cardholder($this->tenant, ['full_name' => 'Jane Doe', 'employee_no' => 'E-100']);
    $this->john = cardholder($this->tenant, ['full_name' => 'John Roe', 'employee_no' => 'E-200']);
    $this->sam = $in(fn () => FamilyMember::factory()->create(['tenant_id' => $this->tenant->id, 'cardholder_id' => $this->jane->id, 'name' => 'Sam Doe', 'relation' => 'son']));
    [$this->head, $this->branch] = $in(fn () => [
        AppointmentLocation::factory()->create(['tenant_id' => $this->tenant->id, 'name' => 'Head office']),
        AppointmentLocation::factory()->create(['tenant_id' => $this->tenant->id, 'name' => 'Branch']),
    ]);
    $book = fn (int $n, array $values) => $in(fn () => Appointment::factory()->create([
        'tenant_id' => $this->tenant->id, 'number' => sprintf('A-26-%05d', $n), 'location_id' => $this->head->id, 'cardholder_id' => $this->jane->id, 'booked_by_id' => $this->owner->id, ...$values,
    ]));
    $this->one = $book(1, ['date' => '2026-10-15', 'starts_at' => '2026-10-15 03:00:00', 'ends_at' => '2026-10-15 03:30:00', 'note' => 'Bring the old card']);
    $this->two = $book(2, ['date' => '2026-10-16', 'starts_at' => null, 'ends_at' => null, 'location_id' => $this->branch->id, 'service' => 'renewal', 'family_member_id' => $this->sam->id,
        'status' => AppointmentStatus::Pending, 'source' => AppointmentSource::Public, 'booked_by_id' => null]);
    $this->three = $book(3, ['date' => '2026-10-10', 'starts_at' => '2026-10-10 04:00:00', 'ends_at' => '2026-10-10 04:30:00', 'cardholder_id' => $this->john->id,
        'status' => AppointmentStatus::Cancelled, 'cancel_reason' => 'Travelling', 'status_changed_at' => '2026-10-09 05:00:00', 'status_changed_by_id' => $this->owner->id]);

    Sanctum::actingAs($this->owner);
    $this->ids = fn (string $url) => array_column($this->getJson($url)->assertOk()->json('data'), 'id');
});

it('finds the rows the appointments list finds, under every criterion and order — from today on unless asked', function () {
    $queries = [
        '',
        'filter[past]=1',
        'filter[past]=1&sort=-date',
        'filter[from]=2026-10-15&filter[to]=2026-10-15',
        'filter[from]=2026-10-01',
        'filter[search]=sam',
        'filter[search]=A-26-00003&filter[past]=1',
        'filter[status]=pending',
        'filter[service]=renewal',
        "filter[location]={$this->branch->public_id}",
        'filter[source]=public',
        "filter[cardholder]={$this->jane->public_id}&filter[past]=1",
        'sort=-created_at&filter[past]=1',
        'sort=number',
    ];
    foreach ($queries as $query) {
        $list = ($this->ids)("/api/v1/appointments?{$query}");
        expect(($this->ids)("/api/v1/reports/appointments?{$query}"))->toBe($list, $query)
            ->and($list)->not->toBe([], $query);
    }
    // From today on by default; the past one only when asked
    expect(($this->ids)('/api/v1/reports/appointments'))->toBe([$this->one->public_id, $this->two->public_id])
        ->and(($this->ids)('/api/v1/reports/appointments?filter[past]=0'))->toBe([$this->one->public_id, $this->two->public_id])
        ->and(($this->ids)('/api/v1/reports/appointments?filter[past]=1'))->toBe([$this->three->public_id, $this->one->public_id, $this->two->public_id]);
});

it('shows each appointment whole, on the organization\'s clock and in the reader\'s language', function () {
    $columns = array_keys(app(ReportRegistry::class)->get('appointments')->columns());
    $rows = collect($this->getJson('/api/v1/reports/appointments?filter[past]=1&'.http_build_query(['columns' => $columns]))->assertOk()->json('data'))->keyBy('id');

    expect($rows[$this->one->public_id]['values'])->toBe([
        'appointment.date' => '2026-10-15',
        'appointment.time' => '09:00–09:30',
        'appointment.number' => 'A-26-00001',
        'appointment.person' => 'Jane Doe',
        'appointment.relation' => null,
        'appointment.cardholder' => 'Jane Doe',
        'appointment.employee_no' => 'E-100',
        'appointment.service' => 'New card',
        'appointment.location' => 'Head office',
        'appointment.status' => 'Confirmed',
        'appointment.source' => 'Staff',
        'appointment.booked_by' => $this->owner->name,
        'appointment.created_at' => '2026-10-14T14:00:00+06:00',
        'appointment.status_changed_by' => null,
        'appointment.status_changed_at' => null,
        'appointment.note' => 'Bring the old card',
        'appointment.cancel_reason' => null,
    ])
        ->and($rows[$this->one->public_id])->toMatchArray(['number' => 'A-26-00001', 'date' => '2026-10-15', 'status' => 'confirmed'])
        // A family member's: the member, the relation, the cardholder's employee number; a whole day is served in turn
        ->and($rows[$this->two->public_id]['values'])->toMatchArray(['appointment.time' => 'In turn', 'appointment.person' => 'Sam Doe', 'appointment.relation' => 'Son', 'appointment.cardholder' => 'Jane Doe',
            'appointment.employee_no' => 'E-100', 'appointment.service' => 'Card renewal', 'appointment.source' => 'Public link', 'appointment.booked_by' => null, 'appointment.status' => 'Waiting for approval'])
        ->and($rows[$this->three->public_id]['values'])->toMatchArray(['appointment.status' => 'Cancelled', 'appointment.cancel_reason' => 'Travelling',
            'appointment.status_changed_by' => $this->owner->name, 'appointment.status_changed_at' => '2026-10-09T11:00:00+06:00']);

    $bangla = collect($this->getJson('/api/v1/reports/appointments?filter[past]=1&columns[]=appointment.time&columns[]=appointment.status&columns[]=appointment.relation', ['Accept-Language' => 'bn'])->json('data'))->keyBy('id');
    expect($bangla[$this->one->public_id]['values'])->toBe(['appointment.time' => '০৯:০০–০৯:৩০', 'appointment.status' => 'নিশ্চিত', 'appointment.relation' => null])
        ->and($bangla[$this->two->public_id]['values'])->toBe(['appointment.time' => 'আসার ক্রমে', 'appointment.status' => 'অনুমোদনের অপেক্ষায়', 'appointment.relation' => 'পুত্র']);
});

it('counts every appointment and each status over every page, named as the statuses are', function () {
    $this->getJson('/api/v1/reports/appointments?filter[past]=1&per_page=10')->assertJsonPath('meta.totals', [
        'appointments' => 3, 'status_pending' => 1, 'status_confirmed' => 1, 'status_checked_in' => 0, 'status_done' => 0, 'status_no_show' => 0, 'status_cancelled' => 1,
    ]);
    $this->getJson('/api/v1/reports/appointments')->assertJsonPath('meta.totals.appointments', 2)->assertJsonPath('meta.totals.status_cancelled', 0);

    $report = collect($this->getJson('/api/v1/reports', ['Accept-Language' => 'bn'])->json('data'))->keyBy('key')['appointments'];
    $filters = collect($report['filters'])->keyBy('key');
    // One total per status of the registry, each in the statuses' own words
    expect(array_column($report['totals'], 'key'))->toBe(['appointments', ...array_map(fn (AppointmentStatus $s) => "status_{$s->value}", AppointmentStatus::cases())])
        ->and(array_column($report['totals'], 'label'))->toBe(['অ্যাপয়েন্টমেন্ট', ...array_map(fn (AppointmentStatus $s) => __("appointments.statuses.{$s->value}", [], 'bn'), AppointmentStatus::cases())])
        ->and($filters->keys()->all())->toBe(['search', 'from', 'to', 'past', 'location', 'status', 'service', 'source', 'cardholder'])
        ->and($filters['past'])->toMatchArray(['default' => '0', 'options' => [['value' => '0', 'label' => 'আজ থেকে'], ['value' => '1', 'label' => 'আগের দিনসহ']]])
        ->and(array_column($filters['location']['options'], 'label'))->toEqualCanonicalizing(['Head office', 'Branch'])
        ->and($filters['to']['after'])->toBe('from')
        ->and($report['groups'])->toBe(['appointment' => 'অ্যাপয়েন্টমেন্ট', 'person' => 'ব্যক্তি']);

    $this->getJson('/api/v1/reports/appointments?filter[status]=lost')->assertUnprocessable()->assertJsonValidationErrors(['filter.status']);
    $this->getJson('/api/v1/reports/appointments?filter[from]=2026-10-16&filter[to]=2026-10-15')->assertUnprocessable()->assertJsonValidationErrors(['filter.to']);
});

it('names the criteria in documents and downloads the list\'s rows into the audit log', function () {
    $report = app(ReportRegistry::class)->get('appointments');
    app(TenantContext::class)->run($this->tenant, function () use ($report) {
        expect(app(ReportFilterSummary::class)->describe($report, new ReportQuery(['cardholder' => $this->jane->public_id, 'past' => '1', 'status' => 'pending'], 'date')))->toBe([
            ['label' => 'Days', 'value' => 'Past days too'],
            ['label' => 'Status', 'value' => 'Waiting for approval'],
            ['label' => 'Cardholder', 'value' => 'Jane Doe (E-100)'],
        ]);
    });

    $this->get("/api/v1/reports/appointments/export?format=csv&filter[cardholder]={$this->jane->public_id}&filter[past]=1&columns[]=appointment.number&columns[]=appointment.person&columns[]=appointment.status")
        ->assertOk()
        ->assertStreamedContent("\u{FEFF}\"Appointment no\",Person,Status\nA-26-00001,\"Jane Doe\",Confirmed\nA-26-00002,\"Sam Doe\",\"Waiting for approval\"\n");
    $this->getJson('/api/v1/reports/audit-changes?filter[event]=exported&columns[]=audit.changes')->assertJsonCount(1, 'data');
});
