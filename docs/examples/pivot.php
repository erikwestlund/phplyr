<?php

$scores = Frame::fromRows([
    ['student' => 'ann', 'wk1' => 10, 'wk2' => null, 'wk3' => 30],
    ['student' => 'bob', 'wk1' => 7, 'wk2' => 8, 'wk3' => 9],
]);

$long = $scores |> pivot_longer(starts_with('wk'), names_to: 'week', names_prefix: 'wk', values_to: 'points', values_drop_na: true);
echo $long;

echo "\n", $long |> pivot_wider(names_from: 'week', values_from: 'points', names_prefix: 'week_', values_fill: 0, names_sort: true);
