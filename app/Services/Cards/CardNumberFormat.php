<?php

namespace App\Services\Cards;

use Carbon\CarbonInterface;

/**
 * A card number pattern such as "{CODE}/{YY}{SEQ:6}".
 *
 * Tokens: {CODE} card type code, {YYYY} / {YY} year, {MM} month, {SEQ:n} running
 * number padded to n digits (exactly one, 1–10). Other characters are kept as typed.
 */
final readonly class CardNumberFormat
{
    public const DEFAULT = '{CODE}/{YY}{SEQ:6}';

    private const TOKEN = '/\{(CODE|YYYY|YY|MM|SEQ:(\d{1,2}))\}/';

    public function __construct(public string $pattern) {}

    /**
     * @return string|null translated problem, or null when the pattern is usable
     */
    public static function problem(string $pattern): ?string
    {
        if (preg_match_all('/\{SEQ:(\d{1,2})\}/', $pattern, $seq) !== 1 || (int) $seq[1][0] < 1 || (int) $seq[1][0] > 10) {
            return __('cards.number_format.seq_required');
        }
        if (preg_match('/\{[^}]*\}/', preg_replace(self::TOKEN, '', $pattern) ?? '')) {
            return __('cards.number_format.unknown_token');
        }
        if (preg_match('/[^A-Za-z0-9\/\-_.{}:]/', $pattern)) {
            return __('cards.number_format.invalid_characters');
        }

        return null;
    }

    /**
     * The number with every token but {SEQ} filled in; also the sequence key.
     */
    public function prefix(string $code, CarbonInterface $date): string
    {
        return $this->fill($code, $date, null);
    }

    public function render(string $code, CarbonInterface $date, int $sequence): string
    {
        return $this->fill($code, $date, $sequence);
    }

    private function fill(string $code, CarbonInterface $date, ?int $sequence): string
    {
        return (string) preg_replace_callback(self::TOKEN, fn (array $m) => match (true) {
            $m[1] === 'CODE' => strtoupper($code),
            $m[1] === 'YYYY' => $date->format('Y'),
            $m[1] === 'YY' => $date->format('y'),
            $m[1] === 'MM' => $date->format('m'),
            $sequence === null => '',
            default => str_pad((string) $sequence, (int) $m[2], '0', STR_PAD_LEFT),
        }, $this->pattern);
    }
}
