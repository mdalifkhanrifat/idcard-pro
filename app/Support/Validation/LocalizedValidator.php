<?php

namespace App\Support\Validation;

use App\Support\Localization\LocalDigits;
use Illuminate\Support\Str;
use Illuminate\Validation\Validator;

/**
 * Laravel's validator with the numbers in its messages written in the reader's digits ("১ থেকে ২০০০-এর মধ্যে"
 * for a Bangla reader). Only the message text changes: the rule parameters used for checking stay as they are.
 */
class LocalizedValidator extends Validator
{
    /**
     * Rules whose messages show numbers taken from their parameters.
     */
    private const NUMBER_RULES = [
        'between', 'decimal', 'digits', 'digits_between', 'gt', 'gte', 'lt', 'lte', 'max', 'max_digits', 'min',
        'min_digits', 'multiple_of', 'size',
    ];

    /**
     * @param  string  $message
     * @param  string  $attribute
     * @param  string  $rule
     * @param  array<int, string>  $parameters
     * @return string
     */
    public function makeReplacements($message, $attribute, $rule, $parameters)
    {
        if (in_array(Str::snake($rule), self::NUMBER_RULES, true)) {
            $parameters = array_map(fn ($parameter) => is_numeric($parameter) ? $this->digits((string) $parameter) : $parameter, $parameters);
        }

        return parent::makeReplacements($message, $attribute, $rule, $parameters);
    }

    /**
     * gt, gte, lt and lte against another field show that field's size: in the reader's digits too.
     *
     * @param  string  $message
     * @param  string  $attribute
     * @param  string  $rule
     * @param  array<int, string>  $parameters
     * @return string
     */
    protected function replaceGt($message, $attribute, $rule, $parameters)
    {
        if (is_null($value = $this->getValue($parameters[0]))) {
            return parent::replaceGt($message, $attribute, $rule, $parameters);
        }

        return str_replace(':value', $this->digits((string) $this->getSize($attribute, $value)), $message);
    }

    private function digits(string $text): string
    {
        return app(LocalDigits::class)->convert($text, $this->translator->getLocale());
    }
}
