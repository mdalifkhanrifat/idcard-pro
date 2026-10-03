<?php

namespace App\Enums;

enum LoginEventType: string
{
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Locked = 'locked';
    case Blocked = 'blocked';
    case TwoFactorRequired = 'two_factor_required';
    case TwoFactorFailed = 'two_factor_failed';
    case LoggedOut = 'logged_out';
    case PasswordReset = 'password_reset';
}
