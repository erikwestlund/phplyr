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
# A phibble: 8 × 6
  area_m2 bedrooms monthly_rent rent_per_m2 size  rank
  <dbl>   <int>    <dbl>        <dbl>       <chr> <int>
1 93.9    3        2386.54      25.42       large 1
2 92.4    3        2240.1       24.24       large 2
3 66.4    2        2022.29      30.46       large 3
4 74.4    2        1879.25      25.26       large 4
5 57.4    2        1805.03      31.45       small 5
6 45.7    1        1509.24      33.02       small 6
7 49.1    1        1267.85      25.82       small 7
8 37      1        959.51       25.93       small 8
```
