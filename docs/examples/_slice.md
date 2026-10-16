```php
$schools = Frame::fromCsv('schools.csv') |> group_by('school');

// The two best students in each of the first three schools.
echo $schools
    |> slice_max('score', n: 2)
    |> filter(fn ($r) => $r->school <= 'school-03')
    |> select('school', 'score');
```

```{.text .output}
# A phibble: 6 × 2
# Groups: school [3]
  school    score
  <chr>     <dbl>
1 school-01 91.037
2 school-01 88.223
3 school-02 92.083
4 school-02 88.725
5 school-03 83.977
6 school-03 83.115
```
