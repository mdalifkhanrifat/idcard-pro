<?php

namespace App\Enums;

enum CardholderStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';
    case Retired = 'retired';
}
