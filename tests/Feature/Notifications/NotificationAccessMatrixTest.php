<?php

use App\Enums\NotificationStatus;
use App\Models\NotificationMessage;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/*
| Every way into notifications (5.2), for each default role at once: what each may see, change and send. The
| single tests of each endpoint hold the details; this table shows the whole and catches a permission changed.
*/

beforeEach(function () {
    Queue::fake();
    Storage::fake(config('cardholder_files.disk'));
    [$this->tenant, $this->owner] = tenantWithOwner();
    $this->holder = cardholder($this->tenant, ['full_name' => 'Jane Doe', 'email' => 'jane@example.com']);
    $this->failed = fn () => app(TenantContext::class)->run($this->tenant, fn () => NotificationMessage::factory()->create([
        'tenant_id' => $this->tenant->id, 'cardholder_id' => $this->holder->id, 'status' => NotificationStatus::Failed, 'failed_at' => now(),
    ]));
    $this->download = function (string $url): int {
        $response = $this->get($url)->baseResponse;
        if ($response instanceof BinaryFileResponse) {
            unlink($response->getFile()->getPathname());
        }

        return $response->getStatusCode();
    };
});

it('gives each default role exactly its part of notifications', function (string $role, array $expected) {
    $user = $role === 'Owner' ? $this->owner : tenantUser($this->tenant, $role);
    Sanctum::actingAs($user);
    $message = ($this->failed)();

    $actual = [
        'settings' => $this->getJson('/api/v1/notifications/settings')->status(),
        // A partial change, as an integration sends it
        'save settings' => $this->putJson('/api/v1/notifications/settings', ['notifications' => ['log_days' => 200]])->status(),
        'messages' => $this->getJson('/api/v1/notifications/messages')->status(),
        'message' => $this->getJson("/api/v1/notifications/messages/{$message->public_id}")->status(),
        'resend' => $this->postJson("/api/v1/notifications/messages/{$message->public_id}/resend")->status(),
        'test message' => $this->postJson('/api/v1/notifications/test', ['channel' => 'mail', 'address' => 'qa@example.test'])->status(),
        'cardholder messages' => $this->getJson("/api/v1/notifications/messages?filter[cardholder]={$this->holder->public_id}")->status(),
        'report' => $this->getJson('/api/v1/reports/notification-messages')->status(),
        'report download' => ($this->download)('/api/v1/reports/notification-messages/export?format=csv'),
    ];

    expect($actual)->toBe($expected);
})->with([
    'Owner' => ['Owner', ['settings' => 200, 'save settings' => 200, 'messages' => 200, 'message' => 200, 'resend' => 201, 'test message' => 201, 'cardholder messages' => 200, 'report' => 200, 'report download' => 200]],
    // Reads and sends, but the organization's settings are not theirs to change
    'Administrator' => ['Administrator', ['settings' => 200, 'save settings' => 403, 'messages' => 200, 'message' => 200, 'resend' => 201, 'test message' => 201, 'cardholder messages' => 200, 'report' => 200, 'report download' => 200]],
    // Reads only
    'Viewer' => ['Viewer', ['settings' => 200, 'save settings' => 403, 'messages' => 200, 'message' => 200, 'resend' => 403, 'test message' => 403, 'cardholder messages' => 200, 'report' => 200, 'report download' => 403]],
    'Operator' => ['Operator', ['settings' => 403, 'save settings' => 403, 'messages' => 403, 'message' => 403, 'resend' => 403, 'test message' => 403, 'cardholder messages' => 403, 'report' => 403, 'report download' => 403]],
]);

it('shows nothing of another organization, whatever the role', function () {
    [, $otherOwner] = tenantWithOwner('globex', 'owner@globex.test');
    $message = ($this->failed)();
    Sanctum::actingAs($otherOwner);

    $this->getJson("/api/v1/notifications/messages/{$message->public_id}")->assertNotFound();
    $this->postJson("/api/v1/notifications/messages/{$message->public_id}/resend")->assertNotFound();
    expect($this->getJson('/api/v1/notifications/messages')->assertOk()->json('data'))->toBe([])
        ->and($this->getJson("/api/v1/notifications/messages?filter[cardholder]={$this->holder->public_id}")->assertOk()->json('data'))->toBe([])
        ->and($this->getJson('/api/v1/reports/notification-messages')->assertOk()->json('meta.totals.messages'))->toBe(0);
});
