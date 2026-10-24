<?php

namespace App\Services\Notifications;

use App\Data\Notifications\MailExtras;
use App\Models\NotificationMessage;
use Throwable;

/**
 * What a logged message's e-mail carries besides its words (5.7b): asked of its event when the event adds to
 * mail (AddsToMail); nothing for a test message, a one-time code, an event since removed — or when making it
 * fails (reported: the words still go).
 */
class NotificationMailExtras
{
    public function for(NotificationMessage $message): MailExtras
    {
        $event = NotificationSettings::events()[$message->event] ?? null;
        if (! $event instanceof AddsToMail) {
            return new MailExtras;
        }
        try {
            return $event->mailExtras($message, array_values(array_filter(explode(',', (string) $message->languages))));
        } catch (Throwable $failure) {
            report($failure);

            return new MailExtras;
        }
    }
}
