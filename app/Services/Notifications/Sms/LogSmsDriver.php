<?php

namespace App\Services\Notifications\Sms;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Writes each SMS to the application's SMS log (storage/logs/sms.log) instead of sending it: for local runs and
 * until an organization's provider is connected.
 */
class LogSmsDriver implements SmsDriver
{
    public function send(string $to, string $text): ?string
    {
        $id = 'log-'.Str::ulid();
        Log::channel((string) config('notifications.sms.log_channel'))->info('SMS', ['id' => $id, 'to' => $to, 'text' => $text]);

        return $id;
    }
}
