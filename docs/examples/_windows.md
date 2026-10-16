```php
echo Frame::fromCsv('schools.csv')
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
# A phibble: 8 × 8
  study_hours score  prev   change running_mean rank  pct       quartile
  <dbl>       <dbl>  <dbl>  <dbl>  <dbl>        <int> <dbl>     <int>
1 1.31        63.707 NA     NA     63.707       15    0.176471  1
2 2.7         67.242 63.707 3.54   65.4745      14    0.235294  1
3 2.74        60.355 67.242 -6.89  63.768       17    0.0588235 1
4 3.71        68.546 60.355 8.19   64.9625      13    0.294118  2
5 4.38        60.137 68.546 -8.41  63.9974      18    0         1
6 4.89        72.762 60.137 12.63  65.4582      9     0.529412  2
7 5.21        69.67  72.762 -3.09  66.0599      12    0.352941  2
8 5.51        72.147 69.67  2.48   66.8208      10    0.470588  2
```
