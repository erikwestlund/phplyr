```php
$housing = Frame::fromCsv('housing.csv');

// _keep: 'none' keeps only new columns (and grouping columns).
echo $housing |> mutate(rent_k: fn ($r) => $r->monthly_rent / 1000, _keep: 'none') |> head(3);

// _by groups for this call only; mean() is recycled within each group.
echo "\n", $housing
    |> mutate(vs_bedroom_avg: fn ($r, $g) => $r->monthly_rent - mean('monthly_rent')($g), _by: 'bedrooms', _after: 'bedrooms')
    |> head(4);
```

```{.text .output}
# A tibble: 3 × 1
  rent_k
   <dbl>
1   1.88
2   1.81
3   2.02
# A tibble: 4 × 4
  area_m2 bedrooms vs_bedroom_avg monthly_rent
    <dbl>    <int>          <dbl>        <dbl>
1    74.4        2          -22.9        1879.
2    57.4        2          -97.2        1805.
3    66.4        2          120.         2022.
4    49.1        1           22.3        1268.
```
