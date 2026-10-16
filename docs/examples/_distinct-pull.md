```php
$housing = Frame::fromCsv('housing.csv');

echo $housing |> distinct('bedrooms') |> arrange('bedrooms');
echo "\n", json_encode($housing |> arrange('area_m2') |> pull('monthly_rent')), "\n";
echo json_encode($housing |> pull(-1)), "\n";
```

```{.text .output}
# A tibble: 3 × 1
  bedrooms
     <int>
1        1
2        2
3        3
[959.51,1509.24,1267.85,1805.03,2022.29,1879.25,2240.1,2386.54]
[1879.25,1805.03,2022.29,1267.85,1509.24,2240.1,959.51,2386.54]
```
