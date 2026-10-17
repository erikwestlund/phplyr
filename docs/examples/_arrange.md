```php
echo DataFrame::fromCsv('housing.csv')
    |> arrange('bedrooms', desc(fn ($r) => $r->monthly_rent / $r->area_m2));
```

```{.text .output}
# A tibble: 8 × 3
  area_m2 bedrooms monthly_rent
    <dbl>    <int>        <dbl>
1    45.7        1        1509.
2    37          1         960.
3    49.1        1        1268.
4    57.4        2        1805.
5    66.4        2        2022.
6    74.4        2        1879.
7    93.9        3        2387.
8    92.4        3        2240.
```
