<?php

namespace App\Enums;

/**
 * Why an address was not told. Only these are kept and shown, never the connection's own words: those would say what
 * lies behind an address, which a webhook must not be a way to find out.
 */
enum WebhookFailure: string
{
    case Refused = 'refused';
    case Unresolved = 'unresolved';
    case Timeout = 'timeout';
    case Unreachable = 'unreachable';
    case Certificate = 'certificate';
    case Redirected = 'redirected';
    case Rejected = 'rejected';
    // Turned off or deleted before its turn came: nothing was sent, and it is no fault of the address
    case Disabled = 'disabled';

    public function label(): string
    {
        return (string) __("webhooks.failures.{$this->value}");
    }
}
