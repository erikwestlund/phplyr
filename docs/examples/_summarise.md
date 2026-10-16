```php
echo Frame::fromCsv('schools.csv')
    |> summarise(
        students: n(),
        schools: n_distinct('school'),
        median_hours: median('study_hours'),
        q90_score: quantile('score', 0.9),
        iqr_score: iqr('score'),
        top_school: fn ($g) => ($g |> slice_max('score', n: 1) |> pull('school'))[0],
    );
```

```{.text .output}
# A phibble: 1 × 6
  students schools median_hours q90_score iqr_score top_school
  <int>    <int>   <dbl>        <dbl>     <dbl>     <chr>
1 250      12      6.665        86.0983   15.3108   school-06
```
