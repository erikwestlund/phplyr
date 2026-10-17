::: {.panel-tabset group="syntax"}

## Method chain (PHP 8.3+)

```php
$schools = DataFrame::fromCsv('schools.csv');

// Several conditions are combined with AND; NA counts as false.
echo phplyr($schools)
    ->filter(fn ($r) => $r->prior_score > 75, between('study_hours', 2, 6))
    ->head(4);

// filter_out() drops the rows where every condition is true.
echo "\n", phplyr($schools)->filter_out(fn ($r) => $r->prior_score < 70)->count();
```

## Pipe (PHP 8.5)

```php
$schools = DataFrame::fromCsv('schools.csv');

// Several conditions are combined with AND; NA counts as false.
echo $schools
    |> filter(fn ($r) => $r->prior_score > 75, between('study_hours', 2, 6))
    |> head(4);

// filter_out() drops the rows where every condition is true.
echo "\n", $schools |> filter_out(fn ($r) => $r->prior_score < 70) |> count();
```

:::

```{.text .output}
# A tibble: 0 × 4
# ℹ 4 variables: school <chr>, study_hours <dbl>, prior_score <dbl>,
#   score <dbl>
# A tibble: 1 × 1
      n
  <int>
1    59
```
