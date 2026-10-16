```php
echo Frame::fromCsv('housing.csv')
    |> mutate(
        rent_per_m2: fn ($r) => round($r->monthly_rent / $r->area_m2, 2),
        size: if_else(fn ($r) => $r->area_m2 >= 60, 'large', 'small'),
        rank: min_rank(desc('monthly_rent')),
    )
    |> arrange('rank');
```

```{.text .output}
# A tibble: 8 × 6
  area_m2 bedrooms monthly_rent rent_per_m2 size   rank
    <dbl>    <int>        <dbl>       <dbl> <chr> <int>
1    93.9        3        2387.        25.4 large     1
2    92.4        3        2240.        24.2 large     2
3    66.4        2        2022.        30.5 large     3
4    74.4        2        1879.        25.3 large     4
5    57.4        2        1805.        31.4 small     5
6    45.7        1        1509.        33.0 small     6
7    49.1        1        1268.        25.8 small     7
8    37          1         960.        25.9 small     8
```
