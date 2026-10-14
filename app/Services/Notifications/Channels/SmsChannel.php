<?php

namespace App\Services\Notifications\Channels;

use App\Data\Notifications\ChannelReceipt;
use App\Models\NotificationMessage;
use App\Services\Notifications\Sms\SmsDriver;
use RuntimeException;

/**
 * SMS through the configured driver (config('notifications.sms.driver'); locally "log", nothing is sent).
 */
class SmsChannel implements NotificationChannel
{
    public function send(NotificationMessage $message): ChannelReceipt
    {
        $name = (string) config('notifications.sms.driver');
        $class = config("notifications.sms.drivers.{$name}");
        if (! is_string($class)) {
            throw new RuntimeException("No SMS driver called \"{$name}\" is set up.");
        }
        $driver = app($class);
        assert($driver instanceof SmsDriver);

        return new ChannelReceipt($name, $driver->send((string) $message->address, $message->body));
    }
}
