<?php

namespace App\Support;

/**
 * How many rows one page of a list may hold (config/lists.php): the same limit for every list and client.
 */
final class ListPage
{
    /**
     * Rows per page the pages offer to choose from, within the limit.
     *
     * @return array<int, int>
     */
    public static function sizes(): array
    {
        $max = self::max();

        return array_values(array_filter(array_map(intval(...), (array) config('lists.page_sizes')), fn (int $size) => $size >= 1 && $size <= $max));
    }

    public static function max(): int
    {
        return max(1, (int) config('lists.max_per_page', 100));
    }

    /**
     * The request rule for `per_page`.
     *
     * @return array<int, string>
     */
    public static function rule(): array
    {
        return ['sometimes', 'integer', 'between:1,'.self::max()];
    }

    /**
     * A number asked for without validation, kept within the limit.
     */
    public static function clamp(int $perPage): int
    {
        return min(max(1, $perPage), self::max());
    }
}
