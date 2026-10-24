<?php

namespace App\Services\Notifications;

use App\Data\Notifications\EventOption;

/**
 * A notification event with settings of its own (5.7d), e.g. when it goes or how many days before: the
 * organization's choices are kept with the event (events.<key>.options) and read with
 * NotificationSettings::option(). Declaring them here is all it takes for them to be saved, checked, kept in the
 * audit log and offered on the notifications page.
 */
interface HasEventOptions
{
    /**
     * @return array<int, EventOption>
     */
    public function eventOptions(): array;
}
