<?php

namespace App\Support\Media;

use GdImage;

/**
 * Re-encodes uploaded images with GD. Only the decoded pixels survive, so metadata
 * (EXIF, GPS, comments) and anything appended to the file are dropped. JPEG
 * orientation is applied and large images are scaled down. Images with
 * transparency (e.g. scanned signatures) are kept as PNG, everything else is JPEG.
 */
class ImageSanitizer
{
    private const SUPPORTED = [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP];

    private const JPEG_QUALITY = 90;

    /**
     * @throws UnreadableImageException
     */
    public function sanitize(string $path, int $maxDimension): SanitizedImage
    {
        $info = @getimagesize($path);
        $contents = @file_get_contents($path);

        if ($info === false || ! in_array($info[2], self::SUPPORTED, true) || $contents === false) {
            throw new UnreadableImageException('Unsupported or corrupt image.');
        }

        $image = @imagecreatefromstring($contents);
        if ($image === false) {
            throw new UnreadableImageException('Unsupported or corrupt image.');
        }

        if ($info[2] === IMAGETYPE_JPEG) {
            $image = $this->applyOrientation($image, $path);
        }
        $image = $this->scaleDown($image, $maxDimension);

        $transparent = $info[2] !== IMAGETYPE_JPEG && $this->hasTransparency($image);
        $encoded = $transparent ? $this->encodePng($image) : $this->encodeJpeg($image);

        return new SanitizedImage(
            contents: $encoded,
            mimeType: $transparent ? 'image/png' : 'image/jpeg',
            extension: $transparent ? 'png' : 'jpg',
            width: imagesx($image),
            height: imagesy($image),
        );
    }

    private function applyOrientation(GdImage $image, string $path): GdImage
    {
        $exif = function_exists('exif_read_data') ? @exif_read_data($path) : false;
        $orientation = is_array($exif) && isset($exif['Orientation']) ? (int) $exif['Orientation'] : 1;

        if (in_array($orientation, [2, 4, 5, 7], true)) {
            imageflip($image, IMG_FLIP_HORIZONTAL);
        }

        $angle = match ($orientation) {
            3, 4 => 180,
            5, 6 => 270,
            7, 8 => 90,
            default => 0,
        };

        return $angle === 0 ? $image : (imagerotate($image, $angle, 0) ?: $image);
    }

    private function scaleDown(GdImage $image, int $maxDimension): GdImage
    {
        $width = imagesx($image);
        $height = imagesy($image);

        if (max($width, $height) <= $maxDimension) {
            return $image;
        }

        $ratio = $maxDimension / max($width, $height);
        $scaled = imagecreatetruecolor(max(1, (int) round($width * $ratio)), max(1, (int) round($height * $ratio)));
        imagealphablending($scaled, false);
        imagesavealpha($scaled, true);
        imagecopyresampled($scaled, $image, 0, 0, 0, 0, imagesx($scaled), imagesy($scaled), $width, $height);

        return $scaled;
    }

    private function hasTransparency(GdImage $image): bool
    {
        if (! imageistruecolor($image)) {
            return imagecolortransparent($image) >= 0;
        }

        $width = imagesx($image);
        $height = imagesy($image);
        // Sample a grid instead of every pixel; enough to detect a transparent background.
        $step = max(1, (int) floor(min($width, $height) / 50));

        for ($x = 0; $x < $width; $x += $step) {
            for ($y = 0; $y < $height; $y += $step) {
                if (((imagecolorat($image, $x, $y) >> 24) & 0x7F) > 0) {
                    return true;
                }
            }
        }

        return false;
    }

    private function encodePng(GdImage $image): string
    {
        imagesavealpha($image, true);
        ob_start();
        imagepng($image, null, 6);

        return (string) ob_get_clean();
    }

    private function encodeJpeg(GdImage $image): string
    {
        // Flatten onto white so transparent areas do not turn black.
        $canvas = imagecreatetruecolor(imagesx($image), imagesy($image));
        imagefill($canvas, 0, 0, (int) imagecolorallocate($canvas, 255, 255, 255));
        imagecopy($canvas, $image, 0, 0, 0, 0, imagesx($image), imagesy($image));

        ob_start();
        imagejpeg($canvas, null, self::JPEG_QUALITY);

        return (string) ob_get_clean();
    }
}
