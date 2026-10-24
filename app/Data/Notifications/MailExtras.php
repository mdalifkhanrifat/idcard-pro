<?php

namespace App\Data\Notifications;

/**
 * What an event adds to its e-mail besides the words (5.7b): a QR code drawn in the mail (its text and a caption
 * under it) and files attached (e.g. a calendar file). Nothing by default.
 */
final readonly class MailExtras
{
    /**
     * @param  array<int, array{name: string, mime: string, data: string}>  $files
     */
    public function __construct(
        public ?string $qr = null,
        public ?string $qrCaption = null,
        public array $files = [],
    ) {}

    public function isEmpty(): bool
    {
        return $this->qr === null && $this->files === [];
    }
}
