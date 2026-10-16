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
}
