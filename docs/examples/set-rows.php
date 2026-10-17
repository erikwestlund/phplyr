<?php

$stock = DataFrame::fromRows([
    ['sku' => 'A1', 'qty' => 5],
    ['sku' => 'B2', 'qty' => null],
    ['sku' => 'C3', 'qty' => 0],
]);
$delivery = DataFrame::fromRows([['sku' => 'B2', 'qty' => 12], ['sku' => 'D4', 'qty' => 3]]);

echo $stock |> rows_upsert($delivery, by: 'sku');
echo "\n", $stock |> rows_patch($delivery, by: 'sku', unmatched: 'ignore');
echo "\n", $stock |> setdiff(DataFrame::fromRows([['sku' => 'C3', 'qty' => 0]]));
