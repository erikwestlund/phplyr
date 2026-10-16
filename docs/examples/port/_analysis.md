```php
$schools = Frame::fromCsv('schools.csv');

// Clean: drop incomplete rows, band study time, compute the gain.
$clean = $schools
    |> filter(col('score')->isNa()->not(), col('study_hours')->gt(0))
    |> mutate(
        hours_band: case_when([col('study_hours')->lt(4), 'low'], [col('study_hours')->lt(8), 'mid'], default: 'high'),
        gain: col('score')->sub(col('prior_score')),
        passed: if_else(col('score')->ge(75), 1, 0),
    );

// Reshape: one row per student and test.
$long = $clean
    |> mutate(student: row_number())
    |> select('student', 'school', ['prior' => 'prior_score', 'final' => 'score'])
    |> pivot_longer(['prior', 'final'], names_to: 'test', values_to: 'points');

echo $long |> group_by('test') |> summarise(mean: mean('points'), sd: sd('points')), "\n";

// Summarise: students per school and band, then the schools with the largest gains.
echo $clean
    |> count('school', 'hours_band')
    |> pivot_wider(names_from: 'hours_band', values_from: 'n', values_fill: 0)
    |> head(3), "\n";

echo $clean
    |> group_by('school')
    |> summarise(n: n(), mean_gain: mean('gain'))
    |> arrange(desc('mean_gain'))
    |> head(3), "\n";

// Model: linear, logistic, and random-intercept fits.
$coef = fn (array $fit) => implode('  ', array_map(
    fn ($name, $b) => $name.' '.\round($b, 4),
    $fit['columns'],
    $fit['coefficients'],
));
echo $coef($clean |> lm('score ~ study_hours + prior_score')), "\n";
echo $coef($clean |> glm('passed ~ study_hours + prior_score', family: 'binomial')), "\n";
echo $coef($clean |> lmer('score ~ study_hours + prior_score + (1 | school)')), "\n";
```

```{.text .output}
# A tibble: 2 × 3
  test   mean    sd
  <chr> <dbl> <dbl>
1 final  72.0  10.3
2 prior  59.8  12.4
# A tibble: 3 × 4
  school     high   low   mid
  <chr>     <int> <int> <int>
1 school-01     7     4     7
2 school-02     8     8     8
3 school-03     5     3     7
# A tibble: 3 × 3
  school        n mean_gain
  <chr>     <int>     <dbl>
1 school-07    19      24.3
2 school-10    25      14.1
3 school-02    24      13.6
(Intercept) 45.3014  study_hours 1.5691  prior_score 0.273
(Intercept) -6.9865  study_hours 0.5688  prior_score 0.0395
(Intercept) 46.6627  study_hours 1.6141  prior_score 0.2453
```
