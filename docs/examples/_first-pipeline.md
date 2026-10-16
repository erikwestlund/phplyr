```php
$schools = Frame::fromCsv('schools.csv');

echo $schools
    |> filter(fn ($r) => $r->study_hours >= 5)
    |> group_by('school')
    |> summarise(students: n(), avg_score: mean('score'), sd_score: sd('score'))
    |> arrange(desc('avg_score'))
    |> head(5);
```

```{.text .output}
# A phibble: 5 × 4
  school    students avg_score sd_score
  <chr>     <int>    <dbl>     <dbl>
1 school-07 14       86.8961   6.32256
2 school-06 20       80.8947   8.9019
3 school-02 12       79.7081   8.21753
4 school-09 11       79.2775   6.67328
5 school-11 13       77.5778   7.31033
```
