<?php

use App\Support\Audit\JsonChanges;

it('notes only what changed, by path, and never nothing becoming nothing', function () {
    $changes = JsonChanges::between(
        ['a' => ['b' => 1, 'map' => [], 'gone' => null, 'list' => [1, 2]]],
        ['a' => ['b' => 2, 'map' => ['x' => 'y'], 'list' => [2, 1], 'empty' => []]],
        'settings',
    );

    expect($changes['new'])->toEqualCanonicalizing(['settings.a.b' => 2, 'settings.a.map.x' => 'y', 'settings.a.list' => [2, 1]])
        ->and(array_keys($changes['new']))->toEqualCanonicalizing(['settings.a.b', 'settings.a.map.x', 'settings.a.list'])
        ->and($changes['old'])->toEqualCanonicalizing(['settings.a.b' => 1, 'settings.a.map.x' => null, 'settings.a.list' => [1, 2]]);
});
