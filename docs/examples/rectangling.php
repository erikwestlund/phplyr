<?php

use function Phplyr\chop;

$people = DataFrame::fromColumns([
    'id' => [1, 2, 3],
    'info' => [
        ['name' => 'ann', 'langs' => ['php', 'r'], 'city' => ['name' => 'Oslo']],
        ['name' => 'bob', 'langs' => ['sql']],
        null,
    ],
]);

echo $people |> hoist('info', town: ['city', 'name']), "\n";

echo $people |> unnest_wider('info') |> select('id', 'langs') |> unnest_longer('langs', indices_include: true), "\n";

echo DataFrame::fromColumns(['g' => ['a', 'a', 'b'], 'x' => [1, 2, 3]]) |> chop('x');
