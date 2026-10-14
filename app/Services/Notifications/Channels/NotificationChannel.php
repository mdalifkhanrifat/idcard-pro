<?php

namespace App\Services\Notifications\Channels;

use App\Data\Notifications\ChannelReceipt;
use App\Models\NotificationMessage;

/**
 * A way messages go out (config('notifications.channels').<key>.class): a new channel is a class like this
 * and a line there.
 */
interface NotificationChannel
{
    /**
     * Hand a logged message to the channel's service.
     *
     * @throws \Throwable when it could not be handed over (the message is tried again, then marked failed)
     */
    public function send(NotificationMessage $message): ChannelReceipt;
}
