```php
echo Frame::fromRows([
    ['quarter' => 'Q1', 'year' => 2024, 'sales' => 10],
    ['quarter' => 'Q2', 'year' => null, 'sales' => null],
    ['quarter' => 'Q3', 'year' => null, 'sales' => 14],
])
    |> fill('year')
    |> replace_na(['sales' => 0]);
```

```{.text .output}
# A tibble: 3 × 3
  quarter  year sales
  <chr>   <int> <int>
1 Q1       2024    10
2 Q2       2024     0
3 Q3       2024    14
```
