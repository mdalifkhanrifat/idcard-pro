<?php

namespace App\Services\Cards;

use App\Models\Card;
use App\Support\Barcodes\BarcodeValues;
use Illuminate\Support\Str;

/**
 * What will print blank on a card, for people and connected systems (e.g. an HR system issuing cards through
 * the API) before it is printed: photos or signatures the holder does not have, barcodes whose value is
 * missing or cannot be encoded in their format, QR codes with nothing to encode or too much text, and biometric
 * images with nothing printable (none captured, only WSQ, or none captured at issue for a card that keeps those). The same checks as the issue and print pages; hidden elements
 * are ignored. Warnings never block issuing or printing.
 */
class CardWarnings
{
    /**
     * Image elements filled from the holder's files or the signatory.
     */
    public const IMAGE_TYPES = ['photo', 'cardholder_signature', 'signatory_signature'];

    public function __construct(private CardDataResolver $cardData) {}

    /**
     * @return array<int, array<string, string|int>>
     */
    public function forCard(Card $card): array
    {
        return $this->check($card->layout['elements'] ?? [], $this->cardData->forCard($card));
    }

    /**
     * @param  array<int, array<string, mixed>>  $elements  design elements (type, field, style)
     * @param  array{fields: array<string, string>, images: array<string, mixed>}  $values
     * @return array<int, array<string, string|int>> `{type: missing_image, image}`, `{type: missing_biometric, field}`, `{type: barcode, field, format, value,
     *                                               reason: empty|invalid}` or `{type: qr, reason: empty|too_long, level, bytes}`
     */
    public function check(array $elements, array $values): array
    {
        $warnings = [];
        foreach ($elements as $element) {
            $type = (string) ($element['type'] ?? '');
            $style = (array) ($element['style'] ?? []);
            if (! empty($style['hidden'])) {
                continue;
            }
            if (in_array($type, self::IMAGE_TYPES, true) && empty($values['images'][$type])) {
                $warnings["image|{$type}"] = ['type' => 'missing_image', 'image' => $type];
            }
            // A field missing from `biometrics` is not printed by the organization's choice: no warning
            $biometrics = (array) ($values['images']['biometrics'] ?? []);
            if ($type === 'biometric' && filled($element['field'] ?? null) && array_key_exists($element['field'], $biometrics) && $biometrics[$element['field']] === null) {
                $warnings["biometric|{$element['field']}"] = ['type' => 'missing_biometric', 'field' => (string) $element['field']];
            }
            if ($type === 'barcode' && filled($element['field'] ?? null)) {
                $field = (string) $element['field'];
                $format = (string) ($style['barcode_format'] ?? 'code128');
                $value = (string) ($values['fields'][$field] ?? '');
                if (! BarcodeValues::encodable($value, $format)) {
                    $warnings["barcode|{$field}|{$format}"] = ['type' => 'barcode', 'field' => $field, 'format' => $format, 'value' => $value, 'reason' => $value === '' ? 'empty' : 'invalid'];
                }
            }
            if ($type === 'qr_code') {
                $level = (string) ($style['qr_error_correction'] ?? BarcodeValues::DEFAULT_QR_LEVEL);
                $text = $this->qrText((array) ($element['qr_lines'] ?? []), $values['fields']);
                if (! BarcodeValues::fitsQr($text, $level)) {
                    $bytes = strlen($text);
                    $warnings["qr|{$level}|{$bytes}"] = ['type' => 'qr', 'reason' => $text === '' ? 'empty' : 'too_long', 'level' => $level, 'bytes' => $bytes];
                }
            }
        }

        return array_values($warnings);
    }

    /**
     * The text a QR element encodes, as the browser builds it (designer/qr.ts qrText()): one line per entry,
     * a field as "Label:value" or the value alone, fixed text as written; lines without a value are left out.
     *
     * @param  array<int, array<string, string>>  $lines
     * @param  array<string, string>  $fields
     */
    private function qrText(array $lines, array $fields): string
    {
        $text = [];
        foreach ($lines as $line) {
            if (array_key_exists('text', $line)) {
                $text[] = Str::trim((string) $line['text']);

                continue;
            }
            $value = Str::trim((string) ($fields[$line['field'] ?? ''] ?? ''));
            $text[] = $value === '' ? '' : (filled($line['label'] ?? null) ? "{$line['label']}:{$value}" : $value);
        }

        return implode("\n", array_filter($text, fn (string $line) => $line !== ''));
    }
}
