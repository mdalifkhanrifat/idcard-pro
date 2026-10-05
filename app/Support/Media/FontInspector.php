<?php

namespace App\Support\Media;

/**
 * Recognizes font files by their contents (never by the file name) and checks that the table
 * directory is sound. Browsers sanitize fonts again before use; this keeps other files out.
 * The family name is read from the font's name table (TrueType, OpenType and WOFF).
 */
class FontInspector
{
    /**
     * @var array<string, string>
     */
    public const EXTENSIONS = ['truetype' => 'ttf', 'opentype' => 'otf', 'woff' => 'woff', 'woff2' => 'woff2'];

    /**
     * @var array<string, string>
     */
    public const MIME_TYPES = ['truetype' => 'font/ttf', 'opentype' => 'font/otf', 'woff' => 'font/woff', 'woff2' => 'font/woff2'];

    private const SIGNATURES = ["\x00\x01\x00\x00" => 'truetype', 'true' => 'truetype', 'OTTO' => 'opentype', 'wOFF' => 'woff', 'wOF2' => 'woff2'];

    private const MAX_TABLES = 64;

    /** English (United States), the usual Windows name record language. */
    private const WINDOWS_ENGLISH = 0x409;

    /**
     * @throws UnreadableFontException
     */
    public function inspect(string $path): InspectedFont
    {
        $data = (string) @file_get_contents($path);
        if (strlen($data) < 12) {
            throw new UnreadableFontException;
        }

        $signature = substr($data, 0, 4);
        if ($signature === 'ttcf') {
            // Font collections (.ttc) cannot be used by browsers
            throw new UnreadableFontException('collection');
        }
        $format = self::SIGNATURES[$signature] ?? throw new UnreadableFontException;

        return match ($format) {
            'woff' => new InspectedFont($format, $this->familyFromName($this->woffTable($data, 'name'))),
            'woff2' => $this->checkWoff2($data),
            default => new InspectedFont($format, $this->familyFromName($this->sfntTable($data, 'name'))),
        };
    }

    /**
     * TrueType/OpenType: table directory after a 12-byte header; every table must lie inside the file
     * and the tables a font cannot work without must be present.
     */
    private function sfntTable(string $data, string $wanted): ?string
    {
        $count = $this->uint16($data, 4);
        if ($count < 1 || $count > self::MAX_TABLES || strlen($data) < 12 + $count * 16) {
            throw new UnreadableFontException;
        }

        $tables = [];
        for ($i = 0; $i < $count; $i++) {
            $record = 12 + $i * 16;
            $offset = $this->uint32($data, $record + 8);
            $length = $this->uint32($data, $record + 12);
            if ($offset + $length > strlen($data)) {
                throw new UnreadableFontException;
            }
            $tables[substr($data, $record, 4)] = [$offset, $length];
        }
        $this->requireTables(array_keys($tables));

        return isset($tables[$wanted]) ? substr($data, $tables[$wanted][0], $tables[$wanted][1]) : null;
    }

    /**
     * WOFF 1: 44-byte header with the total length, then 20-byte table entries; tables may be zlib-compressed.
     */
    private function woffTable(string $data, string $wanted): ?string
    {
        $count = $this->uint16($data, 12);
        if (strlen($data) < 44 + $count * 20 || $this->uint32($data, 8) !== strlen($data) || $count < 1 || $count > self::MAX_TABLES) {
            throw new UnreadableFontException;
        }

        $tables = [];
        for ($i = 0; $i < $count; $i++) {
            $record = 44 + $i * 20;
            $offset = $this->uint32($data, $record + 4);
            $compressed = $this->uint32($data, $record + 8);
            $original = $this->uint32($data, $record + 12);
            if ($offset + $compressed > strlen($data) || $compressed > $original) {
                throw new UnreadableFontException;
            }
            $tables[substr($data, $record, 4)] = [$offset, $compressed, $original];
        }
        $this->requireTables(array_keys($tables));

        if (! isset($tables[$wanted])) {
            return null;
        }
        [$offset, $compressed, $original] = $tables[$wanted];
        $table = substr($data, $offset, $compressed);
        if ($compressed < $original) {
            $table = @gzuncompress($table, $original);
        }

        return is_string($table) ? $table : null;
    }

    /**
     * WOFF 2 tables are Brotli-compressed; only the header is checked and the family is left to the user.
     */
    private function checkWoff2(string $data): InspectedFont
    {
        $count = $this->uint16($data, 12);
        if (strlen($data) < 48 || $this->uint32($data, 8) !== strlen($data) || $count < 1 || $count > self::MAX_TABLES) {
            throw new UnreadableFontException;
        }

        return new InspectedFont('woff2', null);
    }

    /**
     * @param  array<int, string>  $tags
     */
    private function requireTables(array $tags): void
    {
        $outlines = array_intersect($tags, ['glyf', 'CFF ', 'CFF2']);
        if (! in_array('cmap', $tags, true) || ! in_array('head', $tags, true) || $outlines === []) {
            throw new UnreadableFontException;
        }
    }

    /**
     * Typographic family (name 16) before the legacy family (name 1); Windows English before other records.
     */
    private function familyFromName(?string $table): ?string
    {
        if ($table === null || strlen($table) < 6) {
            return null;
        }
        $count = $this->uint16($table, 2);
        $strings = $this->uint16($table, 4);

        $best = null;
        $bestRank = PHP_INT_MAX;
        for ($i = 0; $i < $count && 6 + ($i + 1) * 12 <= strlen($table); $i++) {
            $record = 6 + $i * 12;
            $platform = $this->uint16($table, $record);
            $language = $this->uint16($table, $record + 4);
            $nameId = $this->uint16($table, $record + 6);
            if (($nameId !== 16 && $nameId !== 1) || ($platform !== 3 && $platform !== 1)) {
                continue;
            }
            $raw = substr($table, $strings + $this->uint16($table, $record + 10), $this->uint16($table, $record + 8));
            $value = $platform === 3 ? @mb_convert_encoding($raw, 'UTF-8', 'UTF-16BE') : @mb_convert_encoding($raw, 'UTF-8', 'ISO-8859-1');
            $value = trim((string) preg_replace('/[^\pL\pN \-_]+/u', '', (string) $value));
            $rank = ($nameId === 16 ? 0 : 4) + ($platform === 3 ? 0 : 2) + ($language === self::WINDOWS_ENGLISH || $platform === 1 ? 0 : 1);
            if ($value !== '' && $rank < $bestRank) {
                [$best, $bestRank] = [mb_substr($value, 0, 60), $rank];
            }
        }

        return $best;
    }

    private function uint16(string $data, int $offset): int
    {
        return $offset + 2 <= strlen($data) ? unpack('n', $data, $offset)[1] : 0;
    }

    private function uint32(string $data, int $offset): int
    {
        return $offset + 4 <= strlen($data) ? unpack('N', $data, $offset)[1] : 0;
    }
}
