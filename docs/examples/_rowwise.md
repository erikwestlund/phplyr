```php
echo Frame::fromRows([
    ['id' => 'a', 'q1' => 3, 'q2' => 5, 'q3' => null],
    ['id' => 'b', 'q1' => 4, 'q2' => 1, 'q3' => 2],
])
    |> rowwise('id')
    |> summarise(total: fn ($row) => Stats::sum(
        [...$row->column('q1'), ...$row->column('q2'), ...$row->column('q3')],
        naRm: true,
    ));
```

```{.text .output}
# A phibble: 2 × 2
# Groups: id [2]
  id    total
  <chr> <int>
1 a     8
2 b     7
```
