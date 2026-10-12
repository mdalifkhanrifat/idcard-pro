<?php

namespace App\Support\Pdf;

/**
 * @font-face rules for pages the PDF engine draws without the app's scripts (e.g. report documents): the
 * same Inter and Noto Sans Bengali the app ships, from its own build, so Bangla is shaped correctly and nothing
 * is fetched from outside.
 */
class PdfFonts
{
    /**
     * Font files of the build: family => [weight => file name pattern].
     *
     * @var array<string, array<int, string>>
     */
    private const FILES = [
        'Inter' => [400 => 'inter-400-normal-*.woff2', 700 => 'inter-700-normal-*.woff2'],
        'Noto Sans Bengali' => [400 => 'noto-sans-bengali-bengali-400-normal-*.woff2', 700 => 'noto-sans-bengali-bengali-700-normal-*.woff2'],
    ];

    public static function css(): string
    {
        $css = '';
        foreach (self::FILES as $family => $weights) {
            foreach ($weights as $weight => $pattern) {
                $file = glob(public_path("build/assets/{$pattern}"))[0] ?? null;
                if ($file !== null) {
                    $css .= "@font-face{font-family:'{$family}';font-weight:{$weight};font-style:normal;font-display:block;src:url('/build/assets/".basename($file)."') format('woff2');}\n";
                }
            }
        }

        return $css;
    }
}
