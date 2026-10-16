```php
$schools = Frame::fromCsv('schools.csv') |> group_by('school');

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
# A phibble: 3 × 7
  school    study_hours_avg study_hours_sd prior_score_avg prior_score_sd score_avg score_sd
  <chr>     <dbl>           <dbl>          <dbl>           <dbl>          <dbl>     <dbl>
1 school-01 6.59778         3.2576         63.0078         12.2531        72.912    8.79829
2 school-02 6.19708         3.57179        59.2638         13.3174        72.9044   10.041
3 school-03 6.51933         3.33815        56.076          11.2669        68.9883   10.7716
# A phibble: 3 × 3
  school    range_study_hours range_score
  <chr>     <dbl>             <dbl>
1 school-01 10.3              30.9
2 school-02 10.8              34.4
3 school-03 10.3              35.7
# A phibble: 3 × 4
  school    study_hours prior_score score
  <chr>     <dbl>       <dbl>       <dbl>
1 school-01 11.58       77          78
2 school-01 2.7         52          67
3 school-01 10.02       79          88
```
