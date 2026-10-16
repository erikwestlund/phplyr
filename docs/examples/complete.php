<?php

echo Frame::fromRows([
    ['shop' => 'north', 'month' => 1, 'sales' => 10],
    ['shop' => 'north', 'month' => 3, 'sales' => 12],
    ['shop' => 'south', 'month' => 2, 'sales' => 7],
])
    |> complete('shop', month: [1, 2, 3], fill: ['sales' => 0]);
