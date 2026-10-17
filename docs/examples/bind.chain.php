<?php

$a = DataFrame::fromRows([['id' => 1, 'v' => 'a']]);
$b = DataFrame::fromRows([['id' => 2, 'w' => true]]);

echo phplyr($a)->bind_rows($b, _id: 'source');
echo "\n", phplyr($a)->bind_cols(DataFrame::fromRows([['id' => 10]]));
