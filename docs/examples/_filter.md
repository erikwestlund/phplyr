```php
$schools = Frame::fromCsv('schools.csv');

// Several conditions are combined with AND; NA counts as false.
echo $schools
    |> filter(fn ($r) => $r->prior_score > 75, between('study_hours', 2, 6))
    |> head(4);

// filter_out() drops the rows where every condition is true.
echo "\n", $schools |> filter_out(fn ($r) => $r->prior_score < 70) |> count();
```

```{.text .output}
# A phibble: 0 × 4
 school study_hours prior_score score
 <chr>  <dbl>       <dbl>       <dbl>
# A phibble: 1 × 1
  n
  <int>
1 59
```
