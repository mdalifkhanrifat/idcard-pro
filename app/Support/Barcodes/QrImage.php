<?php

namespace App\Support\Barcodes;

use BaconQrCode\Renderer\GDLibRenderer;
use BaconQrCode\Writer;

/**
 * A QR code as a PNG image, made on the server (bacon/bacon-qr-code with GD — nothing leaves the machine), for an
 * e-mail where a picture must be an attached image (5.7b: an appointment's number, read at the desk by 5.5e).
 */
final class QrImage
{
    public static function png(string $text, int $size = 240): string
    {
        return (new Writer(new GDLibRenderer($size, 2)))->writeString($text);
    }
}
