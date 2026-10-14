<?php

namespace App\Services\Notifications\Sms;

/**
 * An SMS provider (config('notifications.sms.drivers')): a provider is a class like this and a line there.
 * Locally only the log driver is used: nothing leaves the machine.
 */
interface SmsDriver
{
    /**
     * Send a text to a phone number; returns the provider's id for it, if it gives one.
     *
     * @throws \Throwable when the provider did not take it
     */
    public function send(string $to, string $text): ?string;
}
