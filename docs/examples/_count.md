```php
$schools = Frame::fromCsv('schools.csv')
    |> mutate(band: case_when(
        [fn ($r) => $r->study_hours < 4, 'light'],
        [fn ($r) => $r->study_hours < 8, 'moderate'],
        default: 'heavy',
    ));

echo $schools |> count('band', sort: true);
echo "\n", $schools |> count('band', wt: 'study_hours', name: 'hours');
```

```{.text .output}
# A tibble: 3 × 2
  band         n
  <chr>    <int>
1 heavy       95
2 moderate    90
3 light       65
# A tibble: 3 × 2
  band     hours
  <chr>    <dbl>
1 heavy     953.
2 light     159.
3 moderate  540.
```
