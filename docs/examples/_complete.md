```php
echo Frame::fromRows([
    ['shop' => 'north', 'month' => 1, 'sales' => 10],
    ['shop' => 'north', 'month' => 3, 'sales' => 12],
    ['shop' => 'south', 'month' => 2, 'sales' => 7],
])
    |> complete('shop', month: [1, 2, 3], fill: ['sales' => 0]);
```

```{.text .output}
# A tibble: 6 × 3
  shop  month sales
  <chr> <int> <int>
1 north     1    10
2 north     2     0
3 north     3    12
4 south     1     0
5 south     2     7
6 south     3     0
```
