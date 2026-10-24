<?php

namespace App\Services\Notifications;

use App\Data\Notifications\MailExtras;
use App\Models\NotificationMessage;

/**
 * An event whose e-mail carries more than words (5.7b): a QR code, a calendar file... — made when the message is
 * sent, from what it is about now, in the message's languages (the first for a file's own words).
 */
interface AddsToMail
{
    /**
     * @param  array<int, string>  $languages  the message's languages, in order
     */
    public function mailExtras(NotificationMessage $message, array $languages): MailExtras;
}
