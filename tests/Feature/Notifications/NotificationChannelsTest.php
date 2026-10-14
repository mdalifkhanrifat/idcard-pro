<?php

use App\Models\Card;
use App\Models\NotificationMessage;
use App\Services\Notifications\Channels\MailChannel;
use App\Services\Notifications\Channels\SmsChannel;
use App\Services\Notifications\Events\CardReadyEvent;
use App\Services\Notifications\NotificationComposer;
use App\Services\Notifications\NotificationSettings;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    Storage::fake(config('cardholder_files.disk'));
    [$this->tenant, $this->owner] = tenantWithOwner();
    $this->tenant->update(['name' => 'Acme Ltd', 'email' => 'hr@acme.test', 'primary_color' => '#123456']);
    Sanctum::actingAs($this->owner);
    $template = cardTemplate($this->tenant, ['code' => 'EMP']);
    $jane = cardholder($this->tenant, ['full_name' => 'Jane Doe']);
    $issued = $this->postJson("/api/v1/cardholders/{$jane->public_id}/cards", ['template' => $template->public_id, 'reason' => 'new', 'issue_date' => '2026-01-05'])->json('data');
    $this->card = app(TenantContext::class)->run($this->tenant, fn () => Card::query()->where('public_id', $issued['id'])->firstOrFail());
    $this->card->update(['expiry_date' => '2028-01-04']);
    $this->compose = fn (array $notifications) => app(TenantContext::class)->run($this->tenant, fn () => app(NotificationComposer::class)->compose(
        app(CardReadyEvent::class), $this->card->fresh(), NotificationSettings::normalize($notifications),
    ));
    $this->message = fn (array $attributes) => app(TenantContext::class)->run($this->tenant, fn () => NotificationMessage::factory()->create(['tenant_id' => $this->tenant->id, ...$attributes]));
});

it('writes the message in the organization\'s languages only, in its order, its own words where it wrote them', function () {
    $number = $this->card->number;

    // Bangla only (the default): Bangla words and digits
    $bangla = ($this->compose)([]);
    expect($bangla['languages'])->toBe(['bn'])
        ->and($bangla['subject'])->toBe("আপনার আইডি কার্ড {$number} তৈরি হয়েছে")
        ->and($bangla['sms'])->toBe("Acme Ltd: আপনার আইডি কার্ড {$number} তৈরি হয়েছে। কার্ডটি ০৪/০১/২০২৮ পর্যন্ত বৈধ।")
        ->and($bangla['body'])->toStartWith('প্রিয় Jane Doe,')->not->toContain('Dear');

    // English only
    $english = ($this->compose)(['languages' => ['en']]);
    expect($english['subject'])->toBe("Your ID card {$number} is ready")
        ->and($english['sms'])->toBe("Acme Ltd: your ID card {$number} is ready. It is valid until 04/01/2028.")
        ->and($english['body'])->not->toContain('প্রিয়');

    // Both, English first, with the organization's own Bangla SMS
    $both = ($this->compose)(['languages' => ['en', 'bn'], 'events' => ['card_ready' => ['templates' => ['bn' => ['sms' => '{holder.name}, কার্ড {card.number} নিন।']]]]]);
    expect($both['languages'])->toBe(['en', 'bn'])
        ->and($both['subject'])->toBe("Your ID card {$number} is ready / আপনার আইডি কার্ড {$number} তৈরি হয়েছে")
        ->and($both['sms'])->toBe("Acme Ltd: your ID card {$number} is ready. It is valid until 04/01/2028.\nJane Doe, কার্ড {$number} নিন।")
        ->and($both['body'])->toContain("Thank you.\n\n— — —\n\nপ্রিয় Jane Doe,");
    // The reader's own language is left as it was
    expect(app()->getLocale())->toBe('en');
});

it('e-mails a message from the organization by name, answered to its own address, its words never read as markup', function () {
    $message = ($this->message)(['channel' => 'mail', 'address' => 'jane@example.com', 'languages' => 'bn,en', 'subject' => 'Card <ready>', 'body' => "Dear Jane & co,\nline two\n\n<b>Second</b> paragraph's end"]);

    $receipt = app(MailChannel::class)->send($message);

    $sent = app('mailer')->getSymfonyTransport()->messages();
    expect($sent)->toHaveCount(1)
        ->and($receipt->driver)->toBe('array')
        ->and($receipt->providerId)->not->toBeEmpty();
    $email = $sent->first()->getOriginalMessage();
    expect($email->getTo()[0]->getAddress())->toBe('jane@example.com')
        ->and($email->getFrom()[0]->getName())->toBe('Acme Ltd')
        ->and($email->getFrom()[0]->getAddress())->toBe(config('mail.from.address'))
        ->and($email->getReplyTo()[0]->getAddress())->toBe('hr@acme.test')
        ->and($email->getSubject())->toBe('Card <ready>')
        ->and($email->getHtmlBody())->toContain('lang="bn"', '#123456', 'Dear Jane &amp; co,<br />', 'line two', '&lt;b&gt;Second&lt;/b&gt; paragraph&#039;s end')->not->toContain('<b>Second')
        // Plain text as written
        ->and($email->getTextBody())->toContain("Acme Ltd\n\nDear Jane & co,\nline two\n\n<b>Second</b> paragraph's end");
});

it('writes an SMS to the SMS log instead of sending it, and refuses a driver that is not set up', function () {
    $file = sys_get_temp_dir().'/sms-test-'.uniqid().'.log';
    config(['logging.channels.sms' => ['driver' => 'single', 'path' => $file, 'level' => 'info']]);
    $message = ($this->message)(['channel' => 'sms', 'address' => '+8801711000000', 'subject' => null, 'body' => 'Acme Ltd: আপনার কার্ড তৈরি হয়েছে।']);

    $receipt = app(SmsChannel::class)->send($message);

    expect($receipt->driver)->toBe('log')
        ->and($receipt->providerId)->toStartWith('log-')
        ->and(file_get_contents($file))->toContain('+8801711000000', $receipt->providerId)
        // The words as they are, Bangla not escaped
        ->and(file_get_contents($file))->toContain('"text":"Acme Ltd: আপনার কার্ড তৈরি হয়েছে।"');
    unlink($file);

    config(['notifications.sms.driver' => 'nowhere']);
    expect(fn () => app(SmsChannel::class)->send($message))->toThrow(RuntimeException::class, 'No SMS driver called "nowhere" is set up.');
});
