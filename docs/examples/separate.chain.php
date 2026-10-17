<?php

$t = DataFrame::fromRows([
    ['id' => 1, 'name' => 'Ada Lovelace', 'tags' => 'math,code'],
    ['id' => 2, 'name' => 'Grace Hopper', 'tags' => 'navy'],
]);

echo phplyr($t)->separate_wider_delim('name', ' ', names: ['first', 'last']);
echo "\n", phplyr($t)->select('id', 'tags')->separate_longer_delim('tags', ',');
echo "\n", phplyr($t)->select('id', 'name')->unite('label', 'id', 'name', sep: ': ');
