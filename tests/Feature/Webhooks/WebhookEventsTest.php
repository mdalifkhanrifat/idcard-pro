<?php

use App\Data\Cards\ImportCardData;
use App\Enums\AppointmentStatus;
use App\Enums\ImportStatus;
use App\Jobs\ImportCardholders;
use App\Jobs\SendWebhook;
use App\Models\AppointmentLocation;
use App\Models\Cardholder;
use App\Models\CardholderFile;
use App\Models\CardholderImport;
use App\Models\CardholderSync;
use App\Models\Role;
use App\Models\User;
use App\Models\Webhook;
use App\Models\WebhookDelivery;
use App\Repositories\Contracts\CardholderSyncRepositoryInterface;
use App\Repositories\Eloquent\CardholderSyncRepository;
use App\Services\Appointments\PublicBooking\PublicAppointmentService;
use App\Services\Cards\CardIssueService;
use App\Services\Webhooks\WebhookEvents;
use App\Services\Webhooks\WebhookNotifier;
use App\Services\Webhooks\WebhookPayloads;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

/*
| 6.2b: every event of the registry, from every way in. Everything happens through the API or the services a page
| uses; what goes out is read from the delivery log.
*/

beforeEach(function () {
    Http::preventStrayRequests();
    Queue::fake();
    Exceptions::fake();
    $this->expectsReport = false;
    Storage::fake(config('cardholder_files.disk'));
    $this->withoutMiddleware(ThrottleRequests::class);
    [$this->tenant, $this->owner] = tenantWithOwner('alpha', 'owner@example.com');
    $this->tenant->update(['timezone' => 'Asia/Dhaka', 'locale' => 'en']);
    $this->owner->unsetRelation('tenant');
    Sanctum::actingAs($this->owner);
    $this->inTenant = fn (Closure $work) => app(TenantContext::class)->run($this->tenant, $work);
    $this->listen = fn (array $events = [], array $attributes = []): Webhook => ($this->inTenant)(fn () => Webhook::factory()->create([
        'tenant_id' => $this->tenant->id, 'events' => $events === [] ? WebhookEvents::keys() : $events, ...$attributes,
    ]));
    $this->told = fn (): array => WebhookDelivery::withoutGlobalScopes()->orderBy('id')->pluck('event')->all();
    $this->last = fn (): array => json_decode(WebhookDelivery::withoutGlobalScopes()->orderByDesc('id')->firstOrFail()->payload, true);
    $this->clear = fn () => DB::table('webhook_deliveries')->delete();
    $this->template = cardTemplate($this->tenant, ['code' => 'EMP']);
    $this->issued = fn (?Cardholder $holder = null, string $reason = 'new') => $this->postJson('/api/v1/cardholders/'.($holder ?? cardholder($this->tenant))->public_id.'/cards', ['template' => $this->template->public_id, 'reason' => $reason])->assertCreated()->json('data');
    $this->good = ['full_name' => 'Rahim Uddin', 'employee_no' => 'E-1'];
});

// A webhook that cannot be told is only reported: without this, a broken description would pass as silence
afterEach(function () {
    if (! $this->expectsReport) {
        Exceptions::assertNothingReported();
    }
});

it('tells of a cardholder added, changed and deleted, and of no change at all', function () {
    ($this->listen)();

    $id = $this->postJson('/api/v1/cardholders', $this->good)->assertCreated()->json('data.id');
    $this->patchJson("/api/v1/cardholders/{$id}", ['full_name' => 'Rahim Uddin'])->assertOk();
    $this->patchJson("/api/v1/cardholders/{$id}", ['full_name' => 'Karim Uddin'])->assertOk();
    expect(($this->last)()['data']['full_name'])->toBe('Karim Uddin');
    $this->deleteJson("/api/v1/cardholders/{$id}")->assertNoContent();

    expect(($this->told)())->toBe(['cardholder.created', 'cardholder.updated', 'cardholder.deleted'])
        ->and(($this->last)()['data']['id'])->toBe($id)
        ->and(($this->last)()['data']['employee_no'])->toBe('E-1');
    Queue::assertPushedOn('webhooks', SendWebhook::class);
});

it('tells only the webhooks that chose the event, are on, and are of this organization', function () {
    $mine = ($this->listen)(['cardholder.created']);
    ($this->listen)(['cardholder.deleted']);
    ($this->listen)(['cardholder.created'], ['enabled' => false]);
    ($this->listen)(['cardholder.created'], ['deleted_at' => now()]);
    [$other] = tenantWithOwner('beta', 'owner@beta.example.com');
    app(TenantContext::class)->run($other, fn () => Webhook::factory()->create(['tenant_id' => $other->id, 'events' => ['cardholder.created']]));

    $this->postJson('/api/v1/cardholders', $this->good)->assertCreated();

    expect(WebhookDelivery::withoutGlobalScopes()->pluck('webhook_id')->all())->toBe([$mine->id]);
});

it('describes nothing when no webhook is listening', function () {
    $this->mock(WebhookPayloads::class, fn ($mock) => $mock->shouldNotReceive('of'));

    $this->postJson('/api/v1/cardholders', $this->good)->assertCreated();

    expect(WebhookDelivery::withoutGlobalScopes()->count())->toBe(0);
});

it('tells of a card issued, printed, changed, replaced and revoked', function () {
    ($this->listen)(['card.issued', 'card.printed', 'card.updated', 'card.revoked']);
    $holder = cardholder($this->tenant);

    $card = ($this->issued)($holder);
    $this->postJson("/api/v1/cards/{$card['id']}/prints")->assertCreated();
    expect(($this->last)()['data']['print_count'])->toBe(1);
    $this->postJson("/api/v1/cards/{$card['id']}/prints")->assertCreated();
    $this->patchJson("/api/v1/cards/{$card['id']}", ['notes' => 'Lanyard given'])->assertOk();
    $this->patchJson("/api/v1/cards/{$card['id']}", ['notes' => 'Lanyard given'])->assertOk();

    expect(($this->told)())->toBe(['card.issued', 'card.printed', 'card.printed', 'card.updated']);
    ($this->clear)();

    $second = ($this->issued)($holder, 'damaged');
    expect(($this->told)())->toBe(['card.updated', 'card.issued']);
    $replaced = json_decode(WebhookDelivery::withoutGlobalScopes()->orderBy('id')->firstOrFail()->payload, true);
    expect($replaced['data'])->toMatchArray(['id' => $card['id'], 'status' => 'replaced'])
        ->and($replaced['data']['replaced_by']['id'])->toBe($second['id']);
    ($this->clear)();

    $this->postJson("/api/v1/cards/{$second['id']}/revoke", ['note' => 'Lost'])->assertOk();
    expect(($this->told)())->toBe(['card.revoked'])
        ->and(($this->last)()['data'])->toMatchArray(['status' => 'revoked', 'status_note' => 'Lost']);
});

it('tells of each card of a batch printing, and of a card that came in an import', function () {
    ($this->listen)(['card.issued', 'card.printed']);
    $first = ($this->issued)();
    $second = ($this->issued)();
    ($this->clear)();

    $this->postJson('/api/v1/cards/prints', ['cards' => [$first['id'], $second['id']]])->assertCreated();
    expect(($this->told)())->toBe(['card.printed', 'card.printed']);
    ($this->clear)();

    $holder = cardholder($this->tenant);
    ($this->inTenant)(fn () => app(CardIssueService::class)->importCard($holder->fresh(), $this->template->fresh(), new ImportCardData(number: 'EMP/26000099'), $this->owner));

    expect(($this->told)())->toBe(['card.issued'])
        ->and(($this->last)()['data']['number'])->toBe('EMP/26000099');
});

it('tells of an appointment booked, moved and stepped, from staff and from the public link alike', function () {
    $this->tenant->forceFill(['settings' => ['appointments' => ['enabled' => true, 'booking' => ['staff', 'public'], 'slot_minutes' => 30]]])->save();
    $this->owner->unsetRelation('tenant');
    $place = ($this->inTenant)(fn () => AppointmentLocation::factory()->create(['tenant_id' => $this->tenant->id, 'slot_capacity' => 5, 'day_capacity' => 20]));
    $holder = cardholder($this->tenant);
    $this->travelTo(now()->parse('2026-10-16 03:00:00'));
    ($this->listen)(['appointment.booked', 'appointment.confirmed', 'appointment.moved', 'appointment.cancelled', 'appointment.status_changed']);
    $book = ['location' => $place->public_id, 'service' => 'new_card', 'cardholder' => $holder->public_id, 'date' => '2026-10-18', 'time' => '09:30'];

    $id = $this->postJson('/api/v1/appointments', $book)->assertCreated()->json('data.id');
    expect(($this->told)())->toBe(['appointment.booked'])
        ->and(($this->last)()['data'])->toMatchArray(['id' => $id, 'number' => ($this->last)()['data']['number']])
        ->and(($this->last)()['data']['cardholder']['id'])->toBe($holder->public_id);
    ($this->clear)();

    $this->patchJson("/api/v1/appointments/{$id}", ['note' => 'Bring a form'])->assertOk();
    expect(($this->told)())->toBe([]);
    $this->patchJson("/api/v1/appointments/{$id}", ['time' => '10:00'])->assertOk();
    expect(($this->told)())->toBe(['appointment.moved']);
    ($this->clear)();

    $this->postJson("/api/v1/appointments/{$id}/cancel", [])->assertOk();
    expect(($this->told)())->toBe(['appointment.cancelled', 'appointment.status_changed']);
    ($this->clear)();

    $this->postJson("/api/v1/appointments/{$id}/status", ['status' => AppointmentStatus::Confirmed->value])->assertOk();
    expect(($this->told)())->toBe(['appointment.confirmed', 'appointment.status_changed']);
    ($this->clear)();

    $this->travelTo(now()->parse('2026-10-18 04:00:00'));
    $this->postJson("/api/v1/appointments/{$id}/status", ['status' => AppointmentStatus::CheckedIn->value])->assertOk();
    expect(($this->told)())->toBe(['appointment.status_changed']);
    ($this->clear)();

    $public = ($this->inTenant)(fn () => app(PublicAppointmentService::class)->book(cardholder($this->tenant), [...$book, 'date' => '2026-10-20']));
    expect(($this->told)())->toBe(['appointment.booked'])
        ->and(($this->last)()['data']['source']['key'])->toBe('public')
        ->and(($this->last)()['data']['id'])->toBe($public->public_id);
});

it('tells of each row of an imported file that saved, and nothing of a preview or a bad row', function () {
    ($this->listen)(['cardholder.created', 'cardholder.updated']);
    Queue::fake([SendWebhook::class]);
    $file = UploadedFile::fake()->createWithContent('people.csv', "full_name,employee_no,date_of_birth\nRahim Uddin,E-1,1990-04-15\nKarim Mia,E-2,1988-01-31\n,E-3,1980-01-01\n");

    $id = $this->post('/api/v1/cardholder-imports', ['file' => $file], ['Accept' => 'application/json'])->assertCreated()->json('data.id');
    expect(($this->told)())->toBe([]);

    $this->postJson("/api/v1/cardholder-imports/{$id}/start")->assertSuccessful();

    expect(($this->told)())->toBe(['cardholder.created', 'cardholder.created']);
});

it('says in one delivery what a file did, to a webhook that chose that, and of each row to one that did not', function () {
    Queue::fake([SendWebhook::class]);
    $whole = ($this->listen)(['cardholder.imported', 'cardholder.created', 'cardholder.updated']);
    $rows = ($this->listen)(['cardholder.created', 'cardholder.updated']);
    $existing = cardholder($this->tenant, ['employee_no' => 'E-9', 'full_name' => 'Old Name']);
    $file = UploadedFile::fake()->createWithContent('people.csv', "full_name,employee_no,date_of_birth\nRahim Uddin,E-1,1990-04-15\nKarim Mia,E-2,1991-05-16\nNew Name,E-9,1992-06-17\nBad Date,E-3,not-a-date\n");

    $id = $this->post('/api/v1/cardholder-imports', ['file' => $file, 'update_existing' => '1'], ['Accept' => 'application/json'])->assertCreated()->json('data.id');
    $this->postJson("/api/v1/cardholder-imports/{$id}/start")->assertSuccessful();

    $of = fn (Webhook $webhook): array => WebhookDelivery::withoutGlobalScopes()->where('webhook_id', $webhook->id)->orderBy('id')->pluck('event')->all();
    expect($of($whole))->toBe(['cardholder.imported'])
        ->and($of($rows))->toBe(['cardholder.created', 'cardholder.created', 'cardholder.updated']);

    $sent = json_decode(WebhookDelivery::withoutGlobalScopes()->where('webhook_id', $whole->id)->sole()->payload, true)['data'];
    $made = Cardholder::withoutGlobalScopes()->whereIn('employee_no', ['E-1', 'E-2'])->orderBy('id')->pluck('public_id')->all();

    expect($sent['id'])->toBe($id)
        ->and($sent['status'])->toBe('completed')
        ->and([$sent['created'], $sent['updated'], $sent['failed']])->toBe([2, 1, 1])
        ->and($sent['cards'])->toBe(['imported' => 0, 'skipped' => 0])
        ->and($sent['cardholders'])->toBe(['created' => $made, 'updated' => [$existing->public_id]])
        ->and($sent['finished_at'])->toEndWith('Z');
});

it('says nothing of a file where no row saved', function () {
    Queue::fake([SendWebhook::class]);
    ($this->listen)(['cardholder.imported']);
    $file = UploadedFile::fake()->createWithContent('people.csv', "full_name,employee_no\nTaken Number,E-3\n");
    $id = $this->post('/api/v1/cardholder-imports', ['file' => $file], ['Accept' => 'application/json'])->assertCreated()->json('data.id');
    // Valid when it was read, no longer when its turn comes
    cardholder($this->tenant, ['employee_no' => 'E-3']);

    $this->postJson("/api/v1/cardholder-imports/{$id}/start")->assertSuccessful();

    expect(($this->told)())->toBe([])
        ->and(CardholderImport::withoutGlobalScopes()->where('public_id', $id)->value('failed_count'))->toBe(1);
});

it('still tells of the rows that saved when a file breaks off', function () {
    Queue::fake([SendWebhook::class]);
    ($this->listen)(['cardholder.imported']);
    config(['cardholder_import.max_rows' => 3]);
    $file = UploadedFile::fake()->createWithContent('people.csv', "full_name,employee_no\nA One,E-1\nB Two,E-2\n");
    $id = $this->post('/api/v1/cardholder-imports', ['file' => $file], ['Accept' => 'application/json'])->assertCreated()->json('data.id');
    config(['cardholder_import.max_rows' => 1]);
    $this->expectsReport = true;

    $this->postJson("/api/v1/cardholder-imports/{$id}/start");

    $sent = ($this->last)()['data'];
    expect($sent['status'])->toBe('failed')
        ->and($sent['created'])->toBe(1)
        ->and($sent['cardholders']['created'])->toHaveCount(1);
});

it('says the ids are not known when a run is cut off from outside', function () {
    ($this->listen)(['cardholder.imported']);
    $import = ($this->inTenant)(fn () => CardholderImport::query()->create(['original_name' => 'people.csv', 'disk' => 'local', 'status' => ImportStatus::Processing, 'options' => []]));

    (new ImportCardholders($import->id))->failed(new RuntimeException('Timed out'));

    $sent = ($this->last)()['data'];
    expect($sent['status'])->toBe('failed')
        ->and($sent['cardholders'])->toBeNull()
        ->and($sent['created'])->toBeNull();
});

it('says in one delivery what an HR sync request did, to a webhook that chose that, and of each record to one that did not', function () {
    Queue::fake([SendWebhook::class]);
    $whole = ($this->listen)(['cardholder.synced', 'cardholder.created', 'cardholder.updated']);
    $rows = ($this->listen)(['cardholder.created', 'cardholder.updated']);
    $changes = cardholder($this->tenant, ['employee_no' => 'E-8', 'full_name' => 'Old Name']);
    cardholder($this->tenant, ['employee_no' => 'E-9', 'full_name' => 'Same Name']);

    $answer = $this->postJson('/api/v1/sync/cardholders', ['records' => [
        ['employee_no' => 'E-1', 'full_name' => 'Rahim Uddin'],
        ['employee_no' => 'E-8', 'full_name' => 'New Name'],
        ['employee_no' => 'E-9', 'full_name' => 'Same Name'],
        ['employee_no' => 'E-3'],
    ]])->assertOk();

    $of = fn (Webhook $webhook): array => WebhookDelivery::withoutGlobalScopes()->where('webhook_id', $webhook->id)->orderBy('id')->pluck('event')->all();
    expect($of($whole))->toBe(['cardholder.synced'])
        ->and($of($rows))->toBe(['cardholder.created', 'cardholder.updated']);

    $sent = json_decode(WebhookDelivery::withoutGlobalScopes()->where('webhook_id', $whole->id)->sole()->payload, true)['data'];
    $made = Cardholder::withoutGlobalScopes()->where('employee_no', 'E-1')->value('public_id');
    $run = CardholderSync::withoutGlobalScopes()->sole();

    expect($sent['id'])->toBe($answer->json('data.id'))->toBe($run->public_id)
        ->and($sent['kind'])->toBe('upsert')
        ->and([$sent['total'], $sent['created'], $sent['updated'], $sent['unchanged'], $sent['failed'], $sent['not_found']])->toBe([4, 1, 1, 1, 1, 0])
        ->and($sent['cardholders'])->toBe(['created' => [$made], 'updated' => [$changes->public_id]])
        ->and($sent['finished_at'])->toEndWith('Z')
        ->and($run->only(['user_id', 'total', 'created_count', 'updated_count', 'unchanged_count', 'failed_count']))
        ->toBe(['user_id' => $this->owner->id, 'total' => 4, 'created_count' => 1, 'updated_count' => 1, 'unchanged_count' => 1, 'failed_count' => 1]);
});

it('tells of the people who left in one delivery too, as a deactivation, and of each one to a webhook that did not choose that', function () {
    Queue::fake([SendWebhook::class]);
    $whole = ($this->listen)(['cardholder.synced', 'cardholder.updated']);
    $rows = ($this->listen)(['cardholder.updated']);
    $left = cardholder($this->tenant, ['employee_no' => 'E-1']);
    cardholder($this->tenant, ['employee_no' => 'E-2', 'status' => 'inactive']);

    $answer = $this->postJson('/api/v1/sync/cardholders/deactivate', ['employee_nos' => ['E-1', 'E-2', 'E-404']])->assertOk();

    $of = fn (Webhook $webhook): array => WebhookDelivery::withoutGlobalScopes()->where('webhook_id', $webhook->id)->orderBy('id')->pluck('event')->all();
    expect($of($whole))->toBe(['cardholder.synced'])
        ->and($of($rows))->toBe(['cardholder.updated']);

    $sent = json_decode(WebhookDelivery::withoutGlobalScopes()->where('webhook_id', $whole->id)->sole()->payload, true)['data'];
    expect($sent['id'])->toBe($answer->json('data.id'))
        ->and($sent['kind'])->toBe('deactivate')
        ->and([$sent['total'], $sent['created'], $sent['updated'], $sent['unchanged'], $sent['failed'], $sent['not_found']])->toBe([3, 0, 1, 1, 0, 1])
        ->and($sent['cardholders'])->toBe(['created' => [], 'updated' => [$left->public_id]]);

    // Nobody left who was still active: nothing to tell
    ($this->clear)();
    $this->postJson('/api/v1/sync/cardholders/deactivate', ['employee_nos' => ['E-1', 'E-404']])->assertOk();
    expect(($this->told)())->toBe([]);
});

it('keeps no run of a dry run and says nothing of a sync that changed nobody', function () {
    Queue::fake([SendWebhook::class]);
    ($this->listen)(['cardholder.synced']);
    cardholder($this->tenant, ['employee_no' => 'E-9', 'full_name' => 'Same Name']);

    $this->postJson('/api/v1/sync/cardholders', ['dry_run' => true, 'records' => [['employee_no' => 'E-1', 'full_name' => 'A']]])
        ->assertOk()
        ->assertJsonPath('data.id', null);
    expect(CardholderSync::withoutGlobalScopes()->count())->toBe(0);

    $this->postJson('/api/v1/sync/cardholders', ['records' => [['employee_no' => 'E-9', 'full_name' => 'Same Name'], ['employee_no' => 'E-2']]])->assertOk();

    expect(($this->told)())->toBe([])
        ->and(CardholderSync::withoutGlobalScopes()->sole()->only(['unchanged_count', 'failed_count']))->toBe(['unchanged_count' => 1, 'failed_count' => 1]);
});

it('still tells of the records saved when a sync breaks off, and keeps it as unfinished', function () {
    Queue::fake([SendWebhook::class]);
    ($this->listen)(['cardholder.synced']);
    $this->expectsReport = true;
    app()->instance(CardholderSyncRepositoryInterface::class, new class extends CardholderSyncRepository
    {
        public function update(Model $model, array $attributes): Model
        {
            throw new RuntimeException('Gone');
        }
    });

    $this->postJson('/api/v1/sync/cardholders', ['records' => [['employee_no' => 'E-1', 'full_name' => 'A']]])->assertServerError();

    $sent = ($this->last)()['data'];
    expect($sent['created'])->toBe(1)
        ->and($sent['finished_at'])->toBeNull()
        ->and(CardholderSync::withoutGlobalScopes()->sole()->finished_at)->toBeNull()
        ->and(Cardholder::withoutGlobalScopes()->where('employee_no', 'E-1')->exists())->toBeTrue();
});

it('tells of each row again after a file, in the same process', function () {
    $notifier = app(WebhookNotifier::class);
    ($this->listen)(['cardholder.imported', 'cardholder.created']);

    $notifier->inBatch('cardholder.imported', fn () => ($this->inTenant)(fn () => $notifier->tell('cardholder.created', cardholder($this->tenant))));
    expect(($this->told)())->toBe([]);

    ($this->inTenant)(fn () => $notifier->tell('cardholder.created', cardholder($this->tenant)));
    expect(($this->told)())->toBe(['cardholder.created']);
});

it('tells of what a system does with its API token, the same way', function () {
    ($this->listen)(['cardholder.created']);
    $account = ($this->inTenant)(fn () => User::factory()->integration()->create(['tenant_id' => $this->tenant->id])->assignRole('Administrator'));
    app('auth')->forgetGuards();

    $this->withToken($account->createToken('Main', ['*'], now()->addDay())->plainTextToken)->postJson('/api/v1/cardholders', $this->good)->assertCreated();

    expect(($this->told)())->toBe(['cardholder.created']);
});

it('says the same whoever caused it: no links, no reader, in the organization\'s language', function () {
    ($this->listen)(['cardholder.updated']);
    $this->tenant->update(['locale' => 'bn']);
    $this->owner->unsetRelation('tenant');
    $holder = cardholder($this->tenant, ['custom_fields' => []]);
    $file = ($this->inTenant)(fn () => CardholderFile::factory()->create(['tenant_id' => $this->tenant->id, 'cardholder_id' => $holder->id, 'collection' => 'photo']));

    $this->withHeader('Accept-Language', 'en')->patchJson("/api/v1/cardholders/{$holder->public_id}", ['full_name' => 'Changed Name'])->assertOk();

    $payload = WebhookDelivery::withoutGlobalScopes()->sole()->payload;
    $data = json_decode($payload, true)['data'];
    expect($data)->not->toHaveKeys(['photo', 'signature', 'biometric_status', 'current_card', 'created_by', 'has_cards'])
        ->and($payload)->not->toContain('signature=')
        ->and($payload)->not->toContain($file->public_id)
        ->and($payload)->toContain('"custom_fields":{}');
});

it('says nothing of what was undone', function () {
    ($this->listen)();

    try {
        DB::transaction(function () {
            $this->postJson('/api/v1/cardholders', $this->good)->assertCreated();

            throw new RuntimeException('undone');
        });
    } catch (RuntimeException) {
    }

    expect(WebhookDelivery::withoutGlobalScopes()->count())->toBe(0);
});

it('describes a record as saved in the end, not as it was when told', function () {
    ($this->listen)(['cardholder.updated']);
    $holder = cardholder($this->tenant);

    ($this->inTenant)(fn () => DB::transaction(function () use ($holder) {
        app(WebhookNotifier::class)->tell('cardholder.updated', $holder);
        $holder->update(['full_name' => 'Named Last']);
    }));

    expect(($this->last)()['data']['full_name'])->toBe('Named Last');
});

it('never undoes a change when a webhook cannot be told', function () {
    ($this->listen)();
    $this->expectsReport = true;
    $this->mock(WebhookPayloads::class, fn ($mock) => $mock->shouldReceive('of')->andThrow(new RuntimeException('no')));

    $this->postJson('/api/v1/cardholders', $this->good)->assertCreated();

    expect(($this->inTenant)(fn () => Cardholder::query()->where('employee_no', 'E-1')->exists()))->toBeTrue();
    Exceptions::assertReported(RuntimeException::class);
});

it('is quiet without an organization, as in a seeder', function () {
    ($this->listen)();

    app(WebhookNotifier::class)->tell('cardholder.created', new Cardholder);

    expect(WebhookDelivery::withoutGlobalScopes()->count())->toBe(0);
});

it('has every event of the registry fired somewhere', function () {
    $sources = collect(['Cards/CardNotifications', 'Appointments/AppointmentNotifications', 'Cardholders/CardholderService', 'Cardholders/CardholderImportService', 'Cardholders/CardholderApiSyncService', 'Cardholders/FamilyMemberService'])
        ->map(fn (string $file) => file_get_contents(app_path("Services/{$file}.php")))
        // Kinds of file name their own event (config/cardholder_files.php → webhook)
        ->push(collect(config('cardholder_files.collections'))->pluck('webhook')->filter()->map(fn (string $event) => "'{$event}'")->join(''))
        ->join('');

    foreach (WebhookEvents::keys() as $event) {
        expect($sources)->toContain("'{$event}'");
    }
});

it('keeps a person from sending on what they may not see', function () {
    $user = tenantUser($this->tenant, 'Viewer');
    ($this->inTenant)(function () use ($user) {
        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'Connector', 'guard_name' => $user->roles()->first()->guard_name]);
        $role->givePermissionTo('integrations.manage');
        $user->syncRoles([$role]);
    });
    app('auth')->forgetGuards();
    Sanctum::actingAs($user->fresh());
    $good = ['name' => 'Mine', 'url' => 'https://evil.example.com/in', 'events' => ['cardholder.created']];

    $this->postJson('/api/v1/webhooks', $good)->assertUnprocessable()->assertJsonValidationErrors(['events']);

    $theirs = ($this->listen)(['cardholder.created']);
    $this->patchJson("/api/v1/webhooks/{$theirs->public_id}", ['url' => 'https://evil.example.com/in'])->assertUnprocessable()->assertJsonValidationErrors(['events']);
    $this->patchJson("/api/v1/webhooks/{$theirs->public_id}", ['name' => 'Renamed', 'enabled' => false])->assertOk();

    $meta = $this->getJson('/api/v1/webhooks')->assertOk()->json('meta.events');
    expect(collect($meta)->pluck('allowed', 'key')->unique()->all())->toBe(['cardholder' => false]);
    expect($theirs->fresh()->url)->not->toContain('evil');
});

it('has a permission for every group of events', function () {
    expect(array_keys(config('webhooks.requires')))->toEqualCanonicalizing(array_keys(config('webhooks.events')));
    foreach (config('webhooks.requires') as $permission) {
        [$module, $ability] = explode('.', $permission);
        expect(config("permissions.modules.{$module}"))->toContain($ability);
    }
});

it('tells of a family member added, changed and removed, as a change of the cardholder, and of no change at all', function () {
    ($this->listen)(['cardholder.family_changed']);
    $holder = cardholder($this->tenant);
    $members = "/api/v1/cardholders/{$holder->public_id}/family-members";

    $member = $this->postJson($members, ['relation' => 'father', 'name' => 'Abdul Karim'])->assertCreated()->json('data.id');
    expect(($this->last)()['data']['id'])->toBe($holder->public_id)
        ->and(($this->last)()['data']['change'])->toBe(['family_member' => ['id' => $member, 'action' => 'created']]);

    $this->putJson("{$members}/{$member}", ['relation' => 'father', 'name' => 'Abdul Karim'])->assertOk();
    $this->putJson("{$members}/{$member}", ['relation' => 'father', 'name' => 'Abdul Kader'])->assertOk();
    expect(($this->last)()['data']['change']['family_member']['action'])->toBe('updated');
    $this->deleteJson("{$members}/{$member}")->assertNoContent();

    expect(($this->told)())->toBe(['cardholder.family_changed', 'cardholder.family_changed', 'cardholder.family_changed'])
        ->and(($this->last)()['data']['change'])->toBe(['family_member' => ['id' => $member, 'action' => 'deleted']]);
});

it('tells of the cardholder\'s own photo uploaded, replaced and removed: the file\'s id only, never the file or a link', function () {
    ($this->listen)();
    $holder = cardholder($this->tenant);
    $upload = fn (string $collection) => $this->post("/api/v1/cardholders/{$holder->public_id}/files", ['collection' => $collection, 'file' => UploadedFile::fake()->image('me.jpg', 300, 400)], ['Accept' => 'application/json'])->assertCreated()->json('data.id');

    $first = $upload('photo');
    $payload = WebhookDelivery::withoutGlobalScopes()->orderByDesc('id')->firstOrFail()->payload;
    expect(json_decode($payload, true)['data']['change'])->toBe(['file' => ['id' => $first, 'collection' => 'photo', 'action' => 'uploaded']])
        ->and(json_decode($payload, true)['data'])->not->toHaveKey('photo')
        ->and($payload)->not->toContain('signature=')->not->toContain('/files/')->not->toContain('tenants/');

    $second = $upload('photo');
    $this->deleteJson("/api/v1/cardholders/{$holder->public_id}/files/{$second}")->assertNoContent();
    expect(($this->last)()['data']['change']['file'])->toBe(['id' => $second, 'collection' => 'photo', 'action' => 'deleted']);

    // Kinds that name no event are not told of
    ($this->clear)();
    $signature = $upload('signature');
    $this->post("/api/v1/cardholders/{$holder->public_id}/files", ['collection' => 'document', 'file' => UploadedFile::fake()->create('cv.pdf', 10, 'application/pdf')], ['Accept' => 'application/json']);
    $this->deleteJson("/api/v1/cardholders/{$holder->public_id}/files/{$signature}")->assertNoContent();
    expect(($this->told)())->toBe([]);
});

it('tells of a family member\'s photo as a change of the family, not of the cardholder\'s photo', function () {
    ($this->listen)(['cardholder.family_changed', 'cardholder.photo_changed']);
    $holder = cardholder($this->tenant);
    $member = $this->postJson("/api/v1/cardholders/{$holder->public_id}/family-members", ['relation' => 'mother', 'name' => 'Amena'])->assertCreated()->json('data.id');
    ($this->clear)();
    $upload = fn (string $collection) => $this->post("/api/v1/cardholders/{$holder->public_id}/files", ['collection' => $collection, 'family_member' => $member, 'file' => UploadedFile::fake()->image('mum.jpg', 300, 400)], ['Accept' => 'application/json'])->assertCreated()->json('data.id');

    $photo = $upload('photo');
    $upload('signature');

    expect(($this->told)())->toBe(['cardholder.family_changed'])
        ->and(($this->last)()['data']['id'])->toBe($holder->public_id)
        ->and(($this->last)()['data']['change'])->toBe([
            'family_member' => ['id' => $member, 'action' => 'updated'],
            'file' => ['id' => $photo, 'collection' => 'photo', 'action' => 'uploaded'],
        ]);
});

it('tells nothing of a family member or a photo that could not change', function () {
    $this->expectsReport = true;
    ($this->listen)();
    $holder = cardholder($this->tenant);
    $member = ($this->inTenant)(fn () => $holder->familyMembers()->create(['relation' => 'father', 'name' => 'Abdul', 'sort_order' => 1]));
    $card = ($this->issued)($holder);
    ($this->inTenant)(fn () => DB::table('cards')->where('public_id', $card['id'])->update(['family_member_id' => $member->id]));
    ($this->clear)();

    $this->deleteJson("/api/v1/cardholders/{$holder->public_id}/family-members/{$member->public_id}")->assertStatus(422);
    $this->post("/api/v1/cardholders/{$holder->public_id}/files", ['collection' => 'photo', 'file' => UploadedFile::fake()->create('bad.exe', 10)], ['Accept' => 'application/json'])->assertUnprocessable();

    expect(($this->told)())->toBe([]);
});
