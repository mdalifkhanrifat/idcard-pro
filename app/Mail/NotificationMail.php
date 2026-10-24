<?php

namespace App\Mail;

use App\Data\Notifications\MailExtras;
use App\Models\NotificationMessage;
use App\Models\Tenant;
use App\Support\Barcodes\QrImage;
use Illuminate\Mail\Attachment;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\HtmlString;

/**
 * A message of the notification log as an e-mail (5.2): from the organization by name, answered to its own
 * address when it has one; the words exactly as logged, as plain text and as simple HTML (text only, escaped;
 * this application's own addresses — e.g. the public booking page, 5.7e — as links, never any other, since a
 * reason a person typed may hold one);
 * and what its event adds (5.7b): a QR code drawn in the HTML (an embedded image — mail apps block images in
 * data URIs) and attached files.
 */
class NotificationMail extends Mailable
{
    public function __construct(
        public NotificationMessage $notification,
        public Tenant $tenant,
        public MailExtras $extras = new MailExtras,
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
                // Paragraphs as written (a blank line between them), escaped; this app's own addresses as links
                'paragraphs' => array_map(fn (string $paragraph): HtmlString => $this->paragraphHtml($paragraph), preg_split('/\R{2,}/', trim($this->notification->body)) ?: []),
                'organization' => $this->tenant->name,
                'color' => preg_match('/^#[0-9a-fA-F]{6}$/', (string) $this->tenant->primary_color) ? $this->tenant->primary_color : '#0f766e',
                'language' => explode(',', $this->notification->languages)[0],
                'qr' => $this->extras->qr === null ? null : QrImage::png($this->extras->qr),
                'qrCaption' => $this->extras->qrCaption,
            ],
        );
    }

    /**
     * A paragraph as HTML: escaped, line breaks kept, and links to this application's own pages made clickable (up
     * to the first space; a closing full stop, comma or bracket stays text).
     */
    private function paragraphHtml(string $paragraph): HtmlString
    {
        $html = nl2br(e($paragraph));
        $base = rtrim((string) config('app.url'), '/');
        if ($base !== '') {
            $html = (string) preg_replace_callback(
                '#'.preg_quote(e($base), '#').'(?:/[^\s<"]*[^\s<".,;:!?)।])?#u',
                fn (array $match): string => '<a href="'.$match[0].'" style="color:#0f766e;word-break:break-all;">'.$match[0].'</a>',
                $html,
            );
        }

        return new HtmlString($html);
    }

    /**
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        return array_map(
            fn (array $file) => Attachment::fromData(fn () => $file['data'], $file['name'])->withMime($file['mime']),
            $this->extras->files,
        );
    }
}
