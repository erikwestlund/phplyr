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
# A phibble: 6 × 3
  school    p     score
  <chr>     <dbl> <dbl>
1 school-01 0.25  67.568
2 school-01 0.5   72.4545
3 school-01 0.75  76.9065
4 school-02 0.25  65.339
5 school-02 0.5   70.3165
6 school-02 0.75  81.031
```
