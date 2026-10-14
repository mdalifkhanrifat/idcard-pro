<?php

namespace App\Mail;

use App\Models\NotificationMessage;
use App\Models\Tenant;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * A message of the notification log as an e-mail (5.2): from the organization by name, answered to its own
 * address when it has one; the words exactly as logged, as plain text and as simple HTML (text only, escaped).
 */
class NotificationMail extends Mailable
{
    public function __construct(
        public NotificationMessage $notification,
        public Tenant $tenant,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address((string) config('mail.from.address'), $this->tenant->name),
            replyTo: filled($this->tenant->email) ? [new Address((string) $this->tenant->email, $this->tenant->name)] : [],
            subject: (string) $this->notification->subject,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'notifications.mail',
            text: 'notifications.mail-text',
            with: [
                // Paragraphs as written (a blank line between them)
                'paragraphs' => preg_split('/\R{2,}/', trim($this->notification->body)) ?: [],
                'organization' => $this->tenant->name,
                'color' => preg_match('/^#[0-9a-fA-F]{6}$/', (string) $this->tenant->primary_color) ? $this->tenant->primary_color : '#0f766e',
                'language' => explode(',', $this->notification->languages)[0],
            ],
        );
    }
}
