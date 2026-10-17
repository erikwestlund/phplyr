::: {.panel-tabset group="syntax"}

## Method chain (PHP 8.3+)

```php
$schools = phplyr(DataFrame::fromCsv('schools.csv'))->group_by('school')->get();

// The two best students in each of the first three schools.
echo phplyr($schools)
    ->slice_max('score', n: 2)
    ->filter(fn ($r) => $r->school <= 'school-03')
    ->select('school', 'score');
```

## Pipe (PHP 8.5)

```php
$schools = DataFrame::fromCsv('schools.csv') |> group_by('school');

// The two best students in each of the first three schools.
echo $schools
    |> slice_max('score', n: 2)
    |> filter(fn ($r) => $r->school <= 'school-03')
    |> select('school', 'score');
```

:::

```{.text .output}
# A tibble: 6 × 2
# Groups:   school [3]
  school    score
  <chr>     <dbl>
1 school-01  91.0
2 school-01  88.2
3 school-02  92.1
4 school-02  88.7
5 school-03  84.0
6 school-03  83.1
```
