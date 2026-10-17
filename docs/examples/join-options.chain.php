<?php

$x = DataFrame::fromRows([['id' => 1, 'v' => 'a'], ['id' => 2, 'v' => 'b'], ['id' => null, 'v' => 'c']]);
$y = DataFrame::fromRows([['id' => 2, 'v' => 'B'], ['id' => 2, 'v' => 'B2'], ['id' => null, 'v' => 'C']]);

// Clashing columns get suffixes; NA keys match each other, as in dplyr.
echo phplyr($x)->left_join($y, by: 'id');

// Keep only the first match, and never match NA keys.
echo "\n", phplyr($x)->left_join($y, by: 'id', multiple: 'first', na_matches: 'never', suffix: ['_x', '_y']);
