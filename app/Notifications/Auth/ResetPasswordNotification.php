<?php

namespace App\Notifications\Auth;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ResetPasswordNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $token) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $minutes = config('security.password.reset_token_minutes');

        return (new MailMessage)
            ->subject(__('auth.mail.reset_subject'))
            ->greeting(__('auth.mail.greeting', ['name' => $notifiable->name]))
            ->line(__('auth.mail.reset_intro'))
            ->action(__('auth.mail.reset_action'), url('/reset-password?token='.$this->token))
            ->line(__('auth.mail.reset_expiry', ['minutes' => $minutes]))
            ->line(__('auth.mail.reset_ignore'));
    }
}
