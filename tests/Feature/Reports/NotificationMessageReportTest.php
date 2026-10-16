<?php

use App\Data\Reports\ReportQuery;
use App\Enums\NotificationStatus;
use App\Models\Card;
use App\Models\FamilyMember;
use App\Models\NotificationMessage;
use App\Services\Reports\ReportFilterSummary;
use App\Services\Reports\ReportRegistry;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

/*
| The sent messages report (5.2f-2): the notifications log's own criteria, so a download holds the list's rows,
| with the full address and text, totals of how the messages went, and one cardholder's messages from their page.
*/

beforeEach(function () {
    Storage::fake(config('cardholder_files.disk'));
    [$this->tenant, $this->owner] = tenantWithOwner();
    $this->tenant->update(['timezone' => 'Asia/Dhaka']);
    $in = fn (callable $callback) => app(TenantContext::class)->run($this->tenant, $callback);
    $this->jane = cardholder($this->tenant, ['full_name' => 'Jane Doe', 'employee_no' => 'E-100']);
    $this->john = cardholder($this->tenant, ['full_name' => 'John Roe', 'employee_no' => 'E-200']);
    $this->son = $in(fn () => FamilyMember::factory()->create(['tenant_id' => $this->tenant->id, 'cardholder_id' => $this->jane->id, 'name' => 'Sam Doe']));
    $this->card = $in(fn () => Card::factory()->create(['tenant_id' => $this->tenant->id, 'cardholder_id' => $this->jane->id, 'number' => 'EMP/26000042']));
    $log = fn (array $attributes) => $in(fn () => NotificationMessage::factory()->create(['tenant_id' => $this->tenant->id, ...$attributes]));

    // Logged around the organization's midnight (Dhaka, UTC+6) between 5 and 6 April
    $this->ready = $log(['cardholder_id' => $this->jane->id, 'card_id' => $this->card->id, 'address' => 'jane@example.com', 'subject' => 'Card ready', 'body' => 'Your card is ready.',
        'languages' => 'bn,en', 'status' => NotificationStatus::Sent, 'attempts' => 1, 'driver' => 'smtp', 'sent_at' => CarbonImmutable::parse('2026-04-05 23:40', 'Asia/Dhaka')->utc(),
        'created_at' => CarbonImmutable::parse('2026-04-05 23:30', 'Asia/Dhaka')->utc()]);
    $this->sms = $log(['channel' => 'sms', 'family_member_id' => $this->son->id, 'address' => '+8801711000000', 'subject' => null, 'status' => NotificationStatus::Failed,
        'error' => 'Gateway down', 'attempts' => 4, 'failed_at' => now(), 'created_at' => CarbonImmutable::parse('2026-04-06 00:30', 'Asia/Dhaka')->utc()]);
    $this->skipped = $log(['cardholder_id' => $this->john->id, 'address' => null, 'subject' => 'Expiring', 'event' => 'card_expiring', 'status' => NotificationStatus::Skipped,
        'reason' => 'no_address', 'created_at' => CarbonImmutable::parse('2026-04-07 10:00', 'Asia/Dhaka')->utc()]);
    $this->test = $log(['event' => 'test', 'user_id' => $this->owner->id, 'sent_by' => $this->owner->id, 'address' => 'owner@example.com', 'subject' => 'Test',
        'status' => NotificationStatus::Queued, 'resend_of_id' => $this->ready->id, 'created_at' => CarbonImmutable::parse('2026-04-08 10:00', 'Asia/Dhaka')->utc()]);

    Sanctum::actingAs($this->owner);
    $this->ids = fn (string $url) => array_column($this->getJson($url)->assertOk()->json('data'), 'id');
});

it('finds the rows the notifications log finds, under every criterion and order', function () {
    $queries = [
        '',
        'filter[search]=jane',
        'filter[search]=E-100',
        'filter[search]=26000042',
        'filter[status]=failed',
        'filter[channel]=sms',
        'filter[event]=card_expiring',
        'filter[event]=test',
        "filter[cardholder]={$this->jane->public_id}",
        'filter[date_from]=2026-04-06',
        'filter[date_to]=2026-04-05',
        'filter[date_from]=2026-04-06&filter[date_to]=2026-04-07&filter[channel]=mail',
        'sort=created_at',
        'sort=-sent_at',
    ];
    foreach ($queries as $query) {
        $list = ($this->ids)("/api/v1/notifications/messages?{$query}");
        expect(($this->ids)("/api/v1/reports/notification-messages?{$query}"))->toBe($list, $query)
            ->and($list)->not->toBe([], $query);
    }
    // The cardholder's messages are theirs and their family members'
    expect(($this->ids)("/api/v1/reports/notification-messages?filter[cardholder]={$this->jane->public_id}"))->toEqualCanonicalizing([$this->ready->public_id, $this->sms->public_id]);
});

it('shows each message whole: full address and text, whom, how it went and why', function () {
    $columns = array_keys(app(ReportRegistry::class)->get('notification_messages')->columns());
    $rows = collect($this->getJson('/api/v1/reports/notification-messages?'.http_build_query(['columns' => $columns]))->assertOk()->json('data'))->keyBy('id');

    expect($rows[$this->ready->public_id]['values'])->toBe([
        'message.created_at' => '2026-04-05T23:30:00+06:00',
        'message.event' => 'Card ready',
        'message.channel' => 'E-mail',
        'message.address' => 'jane@example.com',
        'message.recipient' => 'Jane Doe',
        'message.recipient_type' => 'Cardholder',
        'message.employee_no' => 'E-100',
        'message.card' => 'EMP/26000042',
        'message.languages' => 'Bangla (বাংলা), English',
        'message.status' => 'Sent',
        'message.reason' => null,
        'message.error' => null,
        'message.attempts' => 1,
        'message.driver' => 'smtp',
        'message.provider_id' => null,
        'message.sent_by' => 'System',
        'message.sent_again' => 'No',
        'message.sent_at' => '2026-04-05T23:40:00+06:00',
        'message.failed_at' => null,
        'message.subject' => 'Card ready',
        'message.body' => 'Your card is ready.',
    ])
        ->and($rows[$this->ready->public_id])->toMatchArray(['event' => 'card_ready', 'channel' => 'mail', 'status' => 'sent'])
        // A family member's message carries the cardholder's employee number
        ->and($rows[$this->sms->public_id]['values'])->toMatchArray(['message.recipient' => 'Sam Doe', 'message.recipient_type' => 'Family member', 'message.employee_no' => 'E-100', 'message.error' => 'Gateway down', 'message.attempts' => 4, 'message.status' => 'Failed'])
        ->and($rows[$this->skipped->public_id]['values'])->toMatchArray(['message.address' => null, 'message.reason' => 'No address on record', 'message.status' => 'Not sent', 'message.event' => 'Card expiring'])
        ->and($rows[$this->test->public_id]['values'])->toMatchArray(['message.event' => 'Test message', 'message.recipient_type' => 'User', 'message.sent_by' => $this->owner->name, 'message.sent_again' => 'Yes']);

    // In Bangla
    $bangla = collect($this->getJson('/api/v1/reports/notification-messages?columns[]=message.status&columns[]=message.reason&columns[]=message.languages', ['Accept-Language' => 'bn'])->json('data'))->keyBy('id');
    expect($bangla[$this->skipped->public_id]['values'])->toBe(['message.status' => 'পাঠানো হয়নি', 'message.reason' => 'ঠিকানা সংরক্ষিত নেই', 'message.languages' => 'বাংলা'])
        ->and($bangla[$this->ready->public_id]['values']['message.languages'])->toBe('বাংলা, ইংরেজি (English)');
});

it('counts how the messages went over every page, and offers the log\'s own choices to filter by', function () {
    $this->getJson('/api/v1/reports/notification-messages?per_page=10')->assertJsonPath('meta.totals', ['messages' => 4, 'sent' => 1, 'failed' => 1, 'skipped' => 1]);
    $this->getJson('/api/v1/reports/notification-messages?filter[channel]=mail')->assertJsonPath('meta.totals', ['messages' => 3, 'sent' => 1, 'failed' => 0, 'skipped' => 1]);

    $report = collect($this->getJson('/api/v1/reports')->json('data'))->keyBy('key')['notification_messages'];
    $filters = collect($report['filters'])->keyBy('key');
    expect($report['default_columns'])->toBe(['message.created_at', 'message.event', 'message.channel', 'message.recipient', 'message.address', 'message.card', 'message.status', 'message.reason'])
        ->and($filters->keys()->all())->toBe(['search', 'status', 'channel', 'event', 'cardholder', 'date_from', 'date_to'])
        ->and(array_column($filters['event']['options'], 'label'))->toContain('Card ready', 'Card expiring', 'Test message')
        ->and(array_column($filters['channel']['options'], 'value'))->toBe(['mail', 'sms'])
        ->and(array_column($filters['status']['options'], 'label'))->toBe(['Waiting', 'Sent', 'Failed', 'Not sent'])
        ->and($filters['cardholder']['type'])->toBe('cardholder')
        ->and($filters['date_to']['after'])->toBe('date_from')
        ->and($report['groups'])->toBe(['message' => 'Message', 'recipient' => 'Recipient']);

    // A wrong choice is refused with the filter's own name
    $this->getJson('/api/v1/reports/notification-messages?filter[status]=lost')->assertUnprocessable()->assertJsonValidationErrors(['filter.status']);
    $this->getJson('/api/v1/reports/notification-messages?filter[date_from]=2026-04-07&filter[date_to]=2026-04-06')->assertUnprocessable()->assertJsonValidationErrors(['filter.date_to']);
});

it('names the cardholder of the criteria in documents', function () {
    $report = app(ReportRegistry::class)->get('notification_messages');
    $summary = fn (array $filter) => app(ReportFilterSummary::class)->describe($report, new ReportQuery($filter, '-created_at'));

    app(TenantContext::class)->run($this->tenant, function () use ($summary) {
        expect($summary(['cardholder' => $this->jane->public_id, 'status' => 'failed']))->toBe([
            ['label' => 'Status', 'value' => 'Failed'],
            ['label' => 'Cardholder', 'value' => 'Jane Doe (E-100)'],
        ]);
    });
});

it('downloads the list\'s rows with the full address and text, and keeps the download in the audit log', function () {
    $this->get('/api/v1/reports/notification-messages/export?format=csv&filter[channel]=sms&columns[]=message.recipient&columns[]=message.address&columns[]=message.status&columns[]=message.error')
        ->assertOk()
        // A cell starting with "+" is quoted so a spreadsheet does not read it as a formula (as every CSV)
        ->assertStreamedContent("\u{FEFF}To,Address,Status,Error\n\"Sam Doe\",'+8801711000000,Failed,\"Gateway down\"\n");

    $this->getJson('/api/v1/reports/audit-changes?filter[event]=exported&columns[]=audit.changes')->assertJsonCount(1, 'data');
});
