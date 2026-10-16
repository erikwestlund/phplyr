```php
// Which schools do better than their students' prior scores predict?
// Fit one regression on all students, then average each school's residuals.
$schools = Frame::fromCsv('schools.csv');
$fit = $schools |> lm('score ~ prior_score + study_hours');
[$b0, $b_prior, $b_hours] = $fit['coefficients'];

echo $schools
    |> mutate(expected: fn ($r) => $b0 + $b_prior * $r->prior_score + $b_hours * $r->study_hours)
    |> mutate(residual: fn ($r) => $r->score - $r->expected)
    |> group_by('school')
    |> summarise(
        students: n(),
        avg_score: mean('score'),
        avg_residual: mean('residual'),
        share_above: fn ($g) => round(\count(array_filter($g->column('residual'), fn ($e) => $e > 0)) / $g->nrow(), 2),
    )
    |> mutate(rank: min_rank(desc('avg_residual')), avg_score: fn ($r) => round($r->avg_score, 1), avg_residual: fn ($r) => round($r->avg_residual, 2))
    |> arrange('rank');
```

```{.text .output}
# A tibble: 12 × 6
   school    students avg_score avg_residual share_above  rank
   <chr>        <int>     <dbl>        <dbl>       <dbl> <int>
 1 school-07       19      83.4        11.1         1        1
 2 school-06       27      76.9         2.28        0.52     2
 3 school-09       16      74.9         2.25        0.69     3
 4 school-02       24      72.9         1.7         0.67     4
 5 school-10       25      71.5         0.66        0.56     5
 6 school-05       20      73           0.23        0.55     6
 7 school-11       23      71.2         0.09        0.39     7
 8 school-01       18      72.9         0.06        0.44     8
 9 school-03       15      69          -1.85        0.4      9
10 school-12       20      64          -5.14        0.05    10
11 school-08       21      64.1        -5.36        0.14    11
12 school-04       22      69.6        -5.95        0.14    12
```
