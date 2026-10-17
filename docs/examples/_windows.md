```php
echo DataFrame::fromCsv('schools.csv')
    |> filter(fn ($r) => $r->school === 'school-01')
    |> arrange('study_hours')
    |> mutate(
        prev: lag('score'),
        change: lag('score')->map(fn ($prev, $r) => $prev === null ? null : round($r->score - $prev, 2)),
        running_mean: cummean('score'),
        rank: min_rank(desc('score')),
        pct: percent_rank('score'),
        quartile: ntile('score', 4),
    )
    |> select('study_hours', 'score', 'prev', 'change', 'running_mean', 'rank', 'pct', 'quartile')
    |> head(8);
```

```{.text .output}
# A tibble: 8 × 8
  study_hours score  prev change running_mean  rank    pct quartile
        <dbl> <dbl> <dbl>  <dbl>        <dbl> <int>  <dbl>    <int>
1        1.31  63.7  NA    NA            63.7    15 0.176         1
2        2.7   67.2  63.7   3.54         65.5    14 0.235         1
3        2.74  60.4  67.2  -6.89         63.8    17 0.0588        1
4        3.71  68.5  60.4   8.19         65.0    13 0.294         2
5        4.38  60.1  68.5  -8.41         64.0    18 0             1
6        4.89  72.8  60.1  12.6          65.5     9 0.529         2
7        5.21  69.7  72.8  -3.09         66.1    12 0.353         2
8        5.51  72.1  69.7   2.48         66.8    10 0.471         2
```
