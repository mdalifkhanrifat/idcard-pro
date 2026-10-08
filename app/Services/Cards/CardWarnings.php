<?php

namespace App\Services\Cards;

use App\Models\Card;
use App\Support\Barcodes\BarcodeValues;

/**
 * What will print blank on a card, for people and connected systems (e.g. an HR system issuing cards through
 * the API) before it is printed: photos or signatures the holder does not have, and barcodes whose value is
 * missing or cannot be encoded in their format. The same checks as the issue and print pages; hidden elements
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
     * @return array<int, array<string, string>>
     */
    public function forCard(Card $card): array
    {
        return $this->check($card->layout['elements'] ?? [], $this->cardData->forCard($card));
    }

    /**
     * @param  array<int, array<string, mixed>>  $elements  design elements (type, field, style)
     * @param  array{fields: array<string, string>, images: array<string, string|null>}  $values
     * @return array<int, array<string, string>> `{type: missing_image, image}` or `{type: barcode, field, format, value, reason: empty|invalid}`
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
            if ($type === 'barcode' && filled($element['field'] ?? null)) {
                $field = (string) $element['field'];
                $format = (string) ($style['barcode_format'] ?? 'code128');
                $value = (string) ($values['fields'][$field] ?? '');
                if (! BarcodeValues::encodable($value, $format)) {
                    $warnings["barcode|{$field}|{$format}"] = ['type' => 'barcode', 'field' => $field, 'format' => $format, 'value' => $value, 'reason' => $value === '' ? 'empty' : 'invalid'];
                }
            }
        }

        return array_values($warnings);
    }
}
