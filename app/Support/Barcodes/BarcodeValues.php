<?php

namespace App\Support\Barcodes;

/**
 * Whether a barcode format can encode a value, by the same rules as JsBarcode 3.12 (which draws the barcodes
 * in the browser), so the server warns about exactly the barcodes that print blank. EAN and UPC take the
 * value without its check digit (JsBarcode adds it) or with a correct one.
 */
final class BarcodeValues
{
    /**
     * @var array<string, string> format (config card_template_elements.barcode_formats) => JsBarcode valid() pattern
     */
    private const PATTERNS = [
        // ASCII plus the function characters 200–211
        'code128' => '/^[\x{00}-\x{7F}\x{C8}-\x{D3}]+$/u',
        'code39' => '/^[0-9A-Z\-. $\/+%]+$/',
        'itf' => '/^(?:[0-9]{2})+$/',
        'codabar' => '/^[A-D][0-9\-$:.+\/]+[A-D]$/',
    ];

    /**
     * Most UTF-8 bytes the largest QR code (version 40) holds per error correction level, as qrcode-generator
     * (which draws QR codes in the browser) encodes the text.
     *
     * @var array<string, int>
     */
    public const QR_MAX_BYTES = ['L' => 2953, 'M' => 2331, 'Q' => 1663, 'H' => 1273];

    public const DEFAULT_QR_LEVEL = 'H';

    /**
     * Whether text fits a QR code at this error correction level (unknown levels count as the default).
     */
    public static function fitsQr(string $text, string $level): bool
    {
        return $text !== '' && strlen($text) <= (self::QR_MAX_BYTES[$level] ?? self::QR_MAX_BYTES[self::DEFAULT_QR_LEVEL]);
    }

    public static function encodable(string $value, string $format): bool
    {
        if ($value === '') {
            return false;
        }

        return match ($format) {
            'ean13' => self::withCheckDigit($value, 12, fn (int $index) => $index % 2 ? 3 : 1),
            'ean8' => self::withCheckDigit($value, 7, fn (int $index) => $index % 2 ? 1 : 3),
            'upc' => self::withCheckDigit($value, 11, fn (int $index) => $index % 2 ? 1 : 3),
            'code39' => preg_match(self::PATTERNS['code39'], mb_strtoupper($value)) === 1,
            // A bare number gets the A start and stop characters
            'codabar' => preg_match(self::PATTERNS['codabar'], mb_strtoupper(preg_match('/^[0-9\-$:.+\/]+$/', $value) ? "A{$value}A" : $value)) === 1,
            'itf' => preg_match(self::PATTERNS['itf'], $value) === 1,
            // Unknown formats draw as Code 128, as in the browser
            default => preg_match(self::PATTERNS['code128'], $value) === 1,
        };
    }

    /**
     * `$length` digits (the check digit is added) or `$length + 1` digits ending in the right check digit.
     *
     * @param  callable(int): int  $weight  weight of the digit at an index
     */
    private static function withCheckDigit(string $value, int $length, callable $weight): bool
    {
        if (preg_match('/^[0-9]{'.$length.'}$/', $value) === 1) {
            return true;
        }
        if (preg_match('/^[0-9]{'.($length + 1).'}$/', $value) !== 1) {
            return false;
        }
        $digits = array_map(intval(...), str_split(substr($value, 0, $length)));
        $sum = 0;
        foreach ($digits as $index => $digit) {
            $sum += $digit * $weight($index);
        }

        return (int) $value[$length] === (10 - $sum % 10) % 10;
    }
}
