```php
// Inside a group, the second closure argument is the group's table, and
// vector helpers such as max() are evaluated per group.
echo Frame::fromCsv('schools.csv')
    |> group_by('school')
    |> filter(fn ($r, $g) => $r->score === max('score')($g))
    |> select('school', 'score')
    |> head(4);
```

```{.text .output}
# A phibble: 4 × 2
# Groups: school [4]
  school    score
  <chr>     <dbl>
1 school-01 91.037
2 school-02 92.083
3 school-03 83.977
4 school-04 83.941
```
