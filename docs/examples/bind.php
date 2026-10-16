<?php

$a = Frame::fromRows([['id' => 1, 'v' => 'a']]);
$b = Frame::fromRows([['id' => 2, 'w' => true]]);

echo $a |> bind_rows($b, _id: 'source');
echo "\n", $a |> bind_cols(Frame::fromRows([['id' => 10]]));
