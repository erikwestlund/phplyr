```php
$schools = DataFrame::fromCsv('schools.csv') |> group_by('school');

// Phplyr helpers can be passed by name; each gets the column name.
echo $schools
    |> summarise(across(where('numeric'), ['avg' => mean(...), 'sd' => sd(...)]))
    |> head(3);

// Other closures get the column's values (summarise) or each value (mutate).
echo "\n", $schools
    |> summarise(across(['study_hours', 'score'], fn (array $v) => round(\max($v) - \min($v), 1), names: 'range_{col}'))
    |> head(3);
echo "\n", $schools |> ungroup() |> mutate(across(ends_with('score'), fn ($v) => round($v))) |> head(3);
```

```{.text .output}
# A tibble: 3 × 7
  school study_hours_avg study_hours_sd prior_score_avg prior_score_sd score_avg
  <chr>            <dbl>          <dbl>           <dbl>          <dbl>     <dbl>
1 schoo…            6.60           3.26            63.0           12.3      72.9
2 schoo…            6.20           3.57            59.3           13.3      72.9
3 schoo…            6.52           3.34            56.1           11.3      69.0
# ℹ 1 more variable: score_sd <dbl>
# A tibble: 3 × 3
  school    range_study_hours range_score
  <chr>                 <dbl>       <dbl>
1 school-01              10.3        30.9
2 school-02              10.8        34.4
3 school-03              10.3        35.7
# A tibble: 3 × 4
  school    study_hours prior_score score
  <chr>           <dbl>       <dbl> <dbl>
1 school-01        11.6          77    78
2 school-01         2.7          52    67
3 school-01        10.0          79    88
```
