<?php

namespace App\Support\Validation;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * One address or one CIDR range, IPv4 or IPv6: "203.0.113.7", "203.0.113.0/24", "2001:db8::/32".
 */
class IpOrCidr implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        [$address, $bits] = array_pad(explode('/', (string) $value, 2), 2, null);
        $validAddress = filter_var($address, FILTER_VALIDATE_IP) !== false;
        $most = str_contains((string) $address, ':') ? 128 : 32;

        if (! $validAddress || ($bits !== null && (! ctype_digit($bits) || (int) $bits > $most))) {
            $fail(__('integrations.not_an_address'));
        }
    }
}
