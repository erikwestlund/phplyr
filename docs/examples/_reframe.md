```php
echo Frame::fromCsv('schools.csv')
    |> filter(fn ($r) => $r->school <= 'school-02')
    |> group_by('school')
    |> reframe(
        p: [0.25, 0.5, 0.75],
        score: fn ($g) => array_map(fn ($p) => Stats::quantile($g->column('score'), $p), [0.25, 0.5, 0.75]),
    );
```

```{.text .output}
# A tibble: 6 × 3
  school        p score
  <chr>     <dbl> <dbl>
1 school-01  0.25  67.6
2 school-01  0.5   72.5
3 school-01  0.75  76.9
4 school-02  0.25  65.3
5 school-02  0.5   70.3
6 school-02  0.75  81.0
```
