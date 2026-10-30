<?php

namespace App\Enums;

/**
 * Which way someone signed in (5.6e): a user of the organization's app, a cardholder proving who they are on the
 * public booking link, or an API token (6.1b: only what was refused — each use is on the token as its last use, one
 * line per request would bury the rest). New ways (SSO...) are one case here and a name in
 * `lang/{en,bn}/reports.php` → `values.login_channels`.
 */
enum LoginChannel: string
{
    case Staff = 'staff';
    case PublicBooking = 'public_booking';
    case Api = 'api';

    public function label(): string
    {
        return (string) (((array) __('reports.values.login_channels'))[$this->value] ?? $this->value);
    }
}
