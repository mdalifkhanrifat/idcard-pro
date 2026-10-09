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

    /**
     * Read a number made with this format (e.g. an imported one): its sequence key and running number,
     * or null when the number does not follow the format for this code.
     *
     * @return array{key: string, sequence: int}|null
     */
    public function parse(string $number, string $code): ?array
    {
        $regex = '';
        $offset = 0;
        preg_match_all(self::TOKEN, $this->pattern, $tokens, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        foreach ($tokens as $token) {
            $regex .= preg_quote(substr($this->pattern, $offset, $token[0][1] - $offset), '/');
            $regex .= match ($token[1][0]) {
                'CODE' => preg_quote(strtoupper($code), '/'),
                'YYYY' => '\d{4}',
                'YY' => '\d{2}',
                'MM' => '(?:0[1-9]|1[0-2])',
                // At least the padded width; longer once the counter outgrew it
                default => '(?<seq>\d{'.(int) $token[2][0].',18})',
            };
            $offset = $token[0][1] + strlen($token[0][0]);
        }
        $regex .= preg_quote(substr($this->pattern, $offset), '/');

        if (! preg_match("/^{$regex}$/", $number, $match, PREG_OFFSET_CAPTURE) || ! isset($match['seq'])) {
            return null;
        }

        // The number without its running part is the prefix it was counted under
        return [
            'key' => substr_replace($number, '', $match['seq'][1], strlen($match['seq'][0])),
            'sequence' => (int) $match['seq'][0],
        ];
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
