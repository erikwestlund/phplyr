```php
echo Frame::fromCsv('housing.csv')
    |> arrange('bedrooms', desc(fn ($r) => $r->monthly_rent / $r->area_m2));
```

```{.text .output}
# A phibble: 8 × 3
  area_m2 bedrooms monthly_rent
  <dbl>   <int>    <dbl>
1 45.7    1        1509.24
2 37      1        959.51
3 49.1    1        1267.85
4 57.4    2        1805.03
5 66.4    2        2022.29
6 74.4    2        1879.25
7 93.9    3        2386.54
8 92.4    3        2240.1
```
