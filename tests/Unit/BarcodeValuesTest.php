<?php

use App\Support\Barcodes\BarcodeValues;

/*
 * Expected results come from JsBarcode 3.12 itself (the browser draws the barcodes), so the server warns
 * about exactly the barcodes that print blank.
 */
it('accepts what the barcode format can encode, like the browser', function (string $format, string $value, bool $encodable) {
    expect(BarcodeValues::encodable($value, $format))->toBe($encodable);
})->with([
    'nothing' => ['code128', '', false],
    'code128 letters and symbols' => ['code128', 'ACME-0031', true],
    'code128 Bangla' => ['code128', 'কার্ড ১২', false],
    'code128 accented letter' => ['code128', 'café', false],
    'code39 capitals' => ['code39', 'SN-001/2026', true],
    'code39 small letters are capitalised' => ['code39', 'acme-0031', true],
    'code39 other symbols' => ['code39', '$12:34', false],
    'ean13 twelve digits' => ['ean13', '123456789012', true],
    'ean13 right check digit' => ['ean13', '4006381333931', true],
    'ean13 wrong check digit' => ['ean13', '4006381333932', false],
    'ean13 letters' => ['ean13', 'ACME-0031', false],
    'ean8 seven digits' => ['ean8', '1234567', true],
    'ean8 right check digit' => ['ean8', '96385074', true],
    'ean8 wrong check digit' => ['ean8', '12345678', false],
    'upc eleven digits' => ['upc', '03600029145', true],
    'upc right check digit' => ['upc', '036000291452', true],
    'upc wrong check digit' => ['upc', '036000291453', false],
    'itf even digits' => ['itf', '1234', true],
    'itf odd digits' => ['itf', '123', false],
    'codabar bare number' => ['codabar', '$12:34', true],
    'codabar start and stop' => ['codabar', 'a1234b', true],
    'codabar wrong start' => ['codabar', 'E1234A', false],
    'unknown format as code128' => ['qr', 'ACME-0031', true],
]);

it('fits QR text up to the largest code at each error correction level', function () {
    expect(BarcodeValues::fitsQr(str_repeat('x', 1273), 'H'))->toBeTrue()
        ->and(BarcodeValues::fitsQr(str_repeat('x', 1274), 'H'))->toBeFalse()
        ->and(BarcodeValues::fitsQr(str_repeat('x', 2953), 'L'))->toBeTrue()
        ->and(BarcodeValues::fitsQr(str_repeat('x', 2954), 'L'))->toBeFalse()
        // Bangla letters take three UTF-8 bytes each
        ->and(BarcodeValues::fitsQr(str_repeat('ক', 425), 'H'))->toBeFalse()
        ->and(BarcodeValues::fitsQr('', 'L'))->toBeFalse();
});
