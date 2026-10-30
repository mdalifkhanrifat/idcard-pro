<?php

namespace App\Enums;

/**
 * Who a user row is. An integration is an organization's account for another system: it holds a role and API tokens
 * like anyone, so every permission check and audit line works unchanged, but it never signs in with a password.
 */
enum UserKind: string
{
    case Person = 'person';
    case Integration = 'integration';
}
