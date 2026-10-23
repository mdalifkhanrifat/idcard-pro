<?php

namespace App\Enums;

/**
 * Where a booking came from: the organization's staff, the person through the public link, or another system
 * through the API (e.g. an HR system).
 */
enum AppointmentSource: string
{
    case Staff = 'staff';
    case Public = 'public';
    case Api = 'api';

    /**
     * Who acts this way, for a booking or step with no user to name ("The person themself (public link)").
     */
    public function actor(): string
    {
        return (string) __("appointments.actors.{$this->value}");
    }
}
