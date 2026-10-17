::: {.panel-tabset group="syntax"}

## Method chain (PHP 8.3+)

```php
$scores = DataFrame::fromRows([
    ['student' => 'ann', 'wk1' => 10, 'wk2' => null, 'wk3' => 30],
    ['student' => 'bob', 'wk1' => 7, 'wk2' => 8, 'wk3' => 9],
]);

$long = phplyr($scores)->pivot_longer(starts_with('wk'), names_to: 'week', names_prefix: 'wk', values_to: 'points', values_drop_na: true)->get();
echo $long;

echo "\n", phplyr($long)->pivot_wider(names_from: 'week', values_from: 'points', names_prefix: 'week_', values_fill: 0, names_sort: true);
```

## Pipe (PHP 8.5)

```php
$scores = DataFrame::fromRows([
    ['student' => 'ann', 'wk1' => 10, 'wk2' => null, 'wk3' => 30],
    ['student' => 'bob', 'wk1' => 7, 'wk2' => 8, 'wk3' => 9],
]);

$long = $scores |> pivot_longer(starts_with('wk'), names_to: 'week', names_prefix: 'wk', values_to: 'points', values_drop_na: true);
echo $long;

echo "\n", $long |> pivot_wider(names_from: 'week', values_from: 'points', names_prefix: 'week_', values_fill: 0, names_sort: true);
```

:::

```{.text .output}
# A tibble: 5 × 3
  student week  points
  <chr>   <chr>  <int>
1 ann     1         10
2 ann     3         30
3 bob     1          7
4 bob     2          8
5 bob     3          9
# A tibble: 2 × 4
  student week_1 week_2 week_3
  <chr>    <int>  <int>  <int>
1 ann         10      0     30
2 bob          7      8      9
```
