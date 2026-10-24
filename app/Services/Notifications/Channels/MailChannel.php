<?php

namespace App\Services\Notifications\Channels;

use App\Data\Notifications\ChannelReceipt;
use App\Mail\NotificationMail;
use App\Models\NotificationMessage;
use App\Services\Notifications\NotificationMailExtras;
use Illuminate\Support\Facades\Mail;

/**
 * E-mail through the application's mailer (config/mail.php; locally Mailpit, nothing leaves the machine).
 */
class MailChannel implements NotificationChannel
{
    public function __construct(private NotificationMailExtras $extras) {}

    public function send(NotificationMessage $message): ChannelReceipt
    {
        $message->loadMissing('tenant');
        $sent = Mail::to((string) $message->address)->send(new NotificationMail($message, $message->tenant, $this->extras->for($message)));

        return new ChannelReceipt((string) config('mail.default'), $sent?->getMessageId());
    }
}
