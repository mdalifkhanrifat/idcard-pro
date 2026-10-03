<?php

return [
    'failed' => 'These credentials do not match our records.',
    'password' => 'The provided password is incorrect.',
    'throttle' => 'Too many login attempts. Please try again in :seconds seconds.',
    'locked' => 'This account is temporarily locked after too many failed attempts. Try again in :minutes minute(s) or contact your administrator.',
    'inactive' => 'This account or organization is inactive. Please contact your administrator.',
    'two_factor_invalid' => 'The authentication code is invalid.',
    'two_factor_expired' => 'Your sign-in session has expired. Please sign in again.',
    'password_changed' => 'Your password has been changed. Other devices have been signed out.',
    'reset_link_sent' => 'If an account matches the details provided, a password reset link has been sent.',
    'reset_token_invalid' => 'This password reset link is invalid or has expired.',
    'password_reset_done' => 'Your password has been reset. You can now sign in.',
    'mail' => [
        'greeting' => 'Hello :name,',
        'reset_subject' => 'Reset your password',
        'reset_intro' => 'We received a request to reset the password for your account.',
        'reset_action' => 'Reset password',
        'reset_expiry' => 'This link expires in :minutes minutes.',
        'reset_ignore' => 'If you did not request a password reset, no action is required.',
    ],
];
