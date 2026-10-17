<?php

$a = DataFrame::fromRows([['id' => 1, 'v' => 'a']]);
$b = DataFrame::fromRows([['id' => 2, 'w' => true]]);

echo $a |> bind_rows($b, _id: 'source');
echo "\n", $a |> bind_cols(DataFrame::fromRows([['id' => 10]]));
