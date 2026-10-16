<?php

namespace App\Support\Localization;

/**
 * Writes the digits 0–9 in a language's own script (config `app.digits`), for text people read: reports,
 * messages, validation errors. Only how numbers look changes — never values that are compared or stored.
 */
class LocalDigits
{
    /**
     * The text with its digits in the language's script; the current language when none is given.
     */
    public function convert(string $text, ?string $locale = null): string
    {
        $digits = config('app.digits.'.($locale ?? app()->getLocale()));

        if (! is_string($digits) || mb_strlen($digits) !== 10) {
            return $text;
        }

        return strtr($text, array_combine(str_split('0123456789'), mb_str_split($digits)));
    }
}
