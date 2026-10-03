<?php

namespace App\Enums;

/**
 * How a cardholder is attached to the organization.
 */
enum EmploymentType: string
{
    case Staff = 'staff';
    case Contractor = 'contractor';
    case Visitor = 'visitor';
    case Student = 'student';
    case Other = 'other';
}
