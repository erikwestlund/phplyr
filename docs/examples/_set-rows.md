```php
$stock = Frame::fromRows([
    ['sku' => 'A1', 'qty' => 5],
    ['sku' => 'B2', 'qty' => null],
    ['sku' => 'C3', 'qty' => 0],
]);
$delivery = Frame::fromRows([['sku' => 'B2', 'qty' => 12], ['sku' => 'D4', 'qty' => 3]]);

echo $stock |> rows_upsert($delivery, by: 'sku');
echo "\n", $stock |> rows_patch($delivery, by: 'sku', unmatched: 'ignore');
echo "\n", $stock |> setdiff(Frame::fromRows([['sku' => 'C3', 'qty' => 0]]));
```

```{.text .output}
# A tibble: 4 × 2
  sku     qty
  <chr> <int>
1 A1        5
2 B2       12
3 C3        0
4 D4        3
# A tibble: 3 × 2
  sku     qty
  <chr> <int>
1 A1        5
2 B2       12
3 C3        0
# A tibble: 2 × 2
  sku     qty
  <chr> <int>
1 A1        5
2 B2       NA
```
