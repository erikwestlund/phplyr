```php
$schools = DataFrame::fromCsv('schools.csv');

echo $schools
    |> filter(fn ($r) => $r->study_hours >= 5)
    |> group_by('school')
    |> summarise(students: n(), avg_score: mean('score'), sd_score: sd('score'))
    |> arrange(desc('avg_score'))
    |> head(5);
```

```{.text .output}
# A tibble: 5 × 4
  school    students avg_score sd_score
  <chr>        <int>     <dbl>    <dbl>
1 school-07       14      86.9     6.32
2 school-06       20      80.9     8.90
3 school-02       12      79.7     8.22
4 school-09       11      79.3     6.67
5 school-11       13      77.6     7.31
```
