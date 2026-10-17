```php
// Price per square meter by number of bedrooms, with each flat's
// position relative to its bedroom group.
$housing = DataFrame::fromCsv('housing.csv')
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
# A tibble: 3 × 8
  bedrooms area_m2_mean area_m2_max monthly_rent_mean monthly_rent_max
     <int>        <dbl>       <dbl>             <dbl>            <dbl>
1        1         43.9        49.1             1246.            1509.
2        2         66.1        74.4             1902.            2022.
3        3         93.2        93.9             2313.            2386.
# ℹ 3 more variables: per_m2_mean <dbl>, per_m2_max <dbl>, flats <int>
# A tibble: 8 × 5
  area_m2 bedrooms monthly_rent per_m2 vs_group
    <dbl>    <int>        <dbl>  <dbl>    <dbl>
1    45.7        1        1509.   33.0     4.76
2    37          1         960.   25.9    -2.33
3    49.1        1        1268.   25.8    -2.44
4    57.4        2        1805.   31.4     2.39
5    66.4        2        2022.   30.5     1.4 
6    74.4        2        1879.   25.3    -3.8 
7    93.9        3        2387.   25.4     0.59
8    92.4        3        2240.   24.2    -0.59
```
