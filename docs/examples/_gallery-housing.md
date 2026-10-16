```php
// Price per square meter by number of bedrooms, with each flat's
// position relative to its bedroom group.
$housing = Frame::fromCsv('housing.csv')
    |> mutate(per_m2: fn ($r) => round($r->monthly_rent / $r->area_m2, 2));

echo $housing
    |> group_by('bedrooms')
    |> summarise(across(['area_m2', 'monthly_rent', 'per_m2'], ['mean' => mean(...), 'max' => max(...)]), flats: n())
    |> mutate(across(where('double'), fn ($v) => round($v, 1)));

echo "\n", $housing
    |> mutate(vs_group: fn ($r, $g) => round($r->per_m2 - mean('per_m2')($g), 2), _by: 'bedrooms')
    |> arrange('bedrooms', desc('vs_group'));
```

```{.text .output}
# A phibble: 3 × 8
  bedrooms area_m2_mean area_m2_max monthly_rent_mean monthly_rent_max per_m2_mean per_m2_max flats
  <int>    <dbl>        <dbl>       <dbl>             <dbl>            <dbl>       <dbl>      <int>
1 1        43.9         49.1        1245.5            1509.2           28.3        33         3
2 2        66.1         74.4        1902.2            2022.3           29.1        31.5       3
3 3        93.2         93.9        2313.3            2386.5           24.8        25.4       2
# A phibble: 8 × 5
  area_m2 bedrooms monthly_rent per_m2 vs_group
  <dbl>   <int>    <dbl>        <dbl>  <dbl>
1 45.7    1        1509.24      33.02  4.76
2 37      1        959.51       25.93  -2.33
3 49.1    1        1267.85      25.82  -2.44
4 57.4    2        1805.03      31.45  2.39
5 66.4    2        2022.29      30.46  1.4
6 74.4    2        1879.25      25.26  -3.8
7 93.9    3        2386.54      25.42  0.59
8 92.4    3        2240.1       24.24  -0.59
```
