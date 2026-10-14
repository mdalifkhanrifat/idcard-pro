<?php

use App\Services\Notifications\Channels\MailChannel;
use App\Services\Notifications\Channels\SmsChannel;
use App\Services\Notifications\Events\CardExpiringEvent;
use App\Services\Notifications\Events\CardReadyEvent;
use App\Services\Notifications\Sms\LogSmsDriver;

/*
|--------------------------------------------------------------------------
| Notifications (Phase 5)
|--------------------------------------------------------------------------
|
| Messages the application sends to people (cardholders first) when something happens: an event of the
| registry below, on the channels and in the words the organization chose (tenants.settings.notifications).
| A new event is a class implementing App\Services\Notifications\NotificationEvent and a line here; a new
| channel a line under `channels`. Nothing leaves the machine locally: mail goes to Mailpit, SMS to the log.
|
*/

return [

    /*
     * Ways a message can go: channel => the recipient's detail it goes to (`address`: e-mail or phone) and the
     * class that sends it (App\Services\Notifications\Channels\NotificationChannel).
     */
    'channels' => [
        'mail' => ['address' => 'email', 'class' => MailChannel::class],
        'sms' => ['address' => 'phone', 'class' => SmsChannel::class],
    ],

    /*
     * SMS providers: driver => class (App\Services\Notifications\Sms\SmsDriver). Only "log" for now: each SMS
     * is written to storage/logs/sms.log and nothing leaves the machine; a real provider is added as a driver
     * with the owner's consent, since its messages leave the machine.
     */
    'sms' => [
        'driver' => env('SMS_DRIVER', 'log'),
        'drivers' => [
            'log' => LogSmsDriver::class,
        ],
        'log_channel' => env('SMS_LOG_CHANNEL', 'sms'),
    ],

    // Placed between the languages of one message, in the organization's order
    'separators' => ['subject' => ' / ', 'body' => "\n\n— — —\n\n", 'sms' => "\n"],

    /*
     * Languages messages can be written in; an organization sends in one of them, or in several (one message,
     * each language in turn).
     */
    'languages' => ['bn', 'en'],

    // Longest texts an organization may write (an SMS of 480 characters is about three Bangla parts)
    'max_length' => ['subject' => 150, 'body' => 5000, 'sms' => 480],

    // Days a sent message stays in the log when the organization has old messages deleted (within these)
    'log_days' => ['min' => 7, 'max' => 3650],

    // What an organization starts with: off until it turns notifications on; the log is kept for good unless
    // the organization chooses to have messages older than `log_days` deleted (daily, app:prune-notification-log)
    'defaults' => [
        'enabled' => false,
        'channels' => ['mail'],
        'languages' => ['bn'],
        'prune_log' => false,
        'log_days' => 365,
    ],

    /*
     * Events: key => class. Each names the values its texts may use ({holder.name}...), its default channels
     * and its default texts (lang/<language>/notifications.php, events.<key>).
     */
    'events' => [
        // A card was issued and can be collected (sent once it is ready, 5.7)
        'card_ready' => CardReadyEvent::class,
        // A card's last day is near (reminder days in 5.7)
        'card_expiring' => CardExpiringEvent::class,
    ],

];
