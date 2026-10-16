<?php

namespace App\Enums;

/**
 * Who books an appointment (config appointments.booking): the organization's staff, or the person themselves
 * through the public link — public bookings keep the notice and only the kinds of visit offered there.
 */
enum BookingAudience: string
{
    case Staff = 'staff';
    case Public = 'public';
}
