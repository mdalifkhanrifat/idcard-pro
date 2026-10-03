<?php

use App\Support\Media\ImageSanitizer;
use App\Support\Media\UnreadableImageException;

/**
 * Write a generated image to a temporary file and return its path.
 */
function imageFile(int $width, int $height, string $format = 'jpeg', bool $transparent = false, string $append = ''): string
{
    $image = imagecreatetruecolor($width, $height);
    imagesavealpha($image, true);
    imagefill($image, 0, 0, $transparent ? imagecolorallocatealpha($image, 0, 0, 0, 127) : imagecolorallocate($image, 30, 90, 160));

    ob_start();
    $format === 'png' ? imagepng($image) : imagejpeg($image);
    $path = tempnam(sys_get_temp_dir(), 'img');
    file_put_contents($path, ob_get_clean().$append);

    return $path;
}

it('scales large images down and keeps the aspect ratio', function () {
    $result = (new ImageSanitizer)->sanitize(imageFile(2400, 1200), 1200);

    expect([$result->width, $result->height])->toBe([1200, 600])
        ->and($result->mimeType)->toBe('image/jpeg')
        ->and(getimagesizefromstring($result->contents)[2])->toBe(IMAGETYPE_JPEG);
});

it('leaves small images at their size', function () {
    $result = (new ImageSanitizer)->sanitize(imageFile(300, 400), 1200);

    expect([$result->width, $result->height])->toBe([300, 400]);
});

it('drops data appended to an image', function () {
    $result = (new ImageSanitizer)->sanitize(imageFile(50, 50, 'png', append: '<?php system($_GET["c"]); ?>'), 1200);

    expect($result->contents)->not->toContain('<?php');
});

it('keeps transparent images as png', function () {
    $result = (new ImageSanitizer)->sanitize(imageFile(200, 80, 'png', transparent: true), 1000);

    expect($result->mimeType)->toBe('image/png')->and($result->extension)->toBe('png');
});

it('converts opaque png to jpeg', function () {
    expect((new ImageSanitizer)->sanitize(imageFile(200, 80, 'png'), 1000)->mimeType)->toBe('image/jpeg');
});

it('rejects files that are not images', function () {
    $path = tempnam(sys_get_temp_dir(), 'img');
    file_put_contents($path, '%PDF-1.4 not an image');

    (new ImageSanitizer)->sanitize($path, 1000);
})->throws(UnreadableImageException::class);

it('applies jpeg exif orientation', function () {
    // Minimal JPEG with an EXIF APP1 segment that sets Orientation = 6 (rotate 90° clockwise).
    $exif = "Exif\0\0".'MM'.pack('n', 42).pack('N', 8).pack('n', 1).pack('nnNn', 0x0112, 3, 1, 6).pack('n', 0).pack('N', 0);
    $jpeg = file_get_contents(imageFile(400, 100));
    $withExif = substr($jpeg, 0, 2)."\xFF\xE1".pack('n', strlen($exif) + 2).$exif.substr($jpeg, 2);
    $path = tempnam(sys_get_temp_dir(), 'img');
    file_put_contents($path, $withExif);

    $result = (new ImageSanitizer)->sanitize($path, 1200);

    expect([$result->width, $result->height])->toBe([100, 400]);
});
