<?php

/**
 * Keys written twice at the same level of a lang file. PHP keeps the last one silently, so a second group with the
 * same name hides the first (appointments.booking: the booking messages hid the names of the ways to book).
 *
 * @return list<string> "file:line key"
 */
function duplicateLangKeys(string $file): array
{
    $duplicates = [];
    $seen = [[]];
    $pendingKey = null;
    foreach (token_get_all((string) file_get_contents($file)) as $token) {
        if (is_array($token)) {
            if ($token[0] === T_CONSTANT_ENCAPSED_STRING) {
                $pendingKey = [trim($token[1], '\'"'), $token[2]];
            } elseif ($token[0] === T_DOUBLE_ARROW && $pendingKey !== null) {
                [$key, $line] = $pendingKey;
                $level = count($seen) - 1;
                if (isset($seen[$level][$key])) {
                    $duplicates[] = basename(dirname($file)).'/'.basename($file).":{$line} {$key}";
                }
                $seen[$level][$key] = true;
            } elseif (! in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                $pendingKey = null;
            }

            continue;
        }
        if ($token === '[') {
            $seen[] = [];
        } elseif ($token === ']') {
            array_pop($seen);
        }
        $pendingKey = null;
    }

    return $duplicates;
}

it('writes no key twice at the same level of a lang file', function () {
    $duplicates = collect(glob(dirname(__DIR__, 2).'/lang/*/*.php'))->flatMap(fn (string $file): array => duplicateLangKeys($file))->all();

    expect($duplicates)->toBe([]);
});

it('finds a key written twice', function () {
    $file = tempnam(sys_get_temp_dir(), 'lang').'.php';
    file_put_contents($file, "<?php\nreturn [\n    'a' => ['x' => 1],\n    'b' => ['x' => 2],\n    'a' => ['y' => 3],\n];\n");

    expect(duplicateLangKeys($file))->toHaveCount(1)
        ->and(duplicateLangKeys($file)[0])->toEndWith(':5 a');
    unlink($file);
});
