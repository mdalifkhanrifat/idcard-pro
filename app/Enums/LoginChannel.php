<?php

namespace App\Enums;

/**
 * Which way someone signed in (5.6e): a user of the organization's app, or a cardholder proving who they are on the
 * public booking link. New ways (SSO, an API token...) are one case here and a name in `lang/{en,bn}/reports.php` →
 * `values.login_channels`.
 */
enum LoginChannel: string
{
    case Staff = 'staff';
    case PublicBooking = 'public_booking';

    public function label(): string
    {
        return (string) (((array) __('reports.values.login_channels'))[$this->value] ?? $this->value);
    }
}
