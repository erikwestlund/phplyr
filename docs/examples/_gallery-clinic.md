::: {.panel-tabset group="syntax"}

## Method chain (PHP 8.3+)

```php
// Calls by staffing level and visitor volume, as a cross-tabulation.
echo phplyr(DataFrame::fromCsv('clinic.csv'))
    ->mutate(
        staffing: case_when([fn ($r) => $r->staff < 4, 'low'], [fn ($r) => $r->staff < 6, 'mid'], default: 'high'),
        volume: if_else(fn ($r) => $r->visitors >= 250, 'busy', 'quiet'),
    )
    ->group_by('staffing', 'volume')
    ->summarise(mean_calls: mean('calls'), _groups: 'drop')
    ->mutate(mean_calls: fn ($r) => round($r->mean_calls, 2))
    ->pivot_wider(names_from: 'volume', values_from: 'mean_calls', names_sort: true)
    ->arrange(fn ($r) => array_search($r->staffing, ['low', 'mid', 'high']));
```

## Pipe (PHP 8.5)

```php
// Calls by staffing level and visitor volume, as a cross-tabulation.
echo DataFrame::fromCsv('clinic.csv')
    |> mutate(
        staffing: case_when([fn ($r) => $r->staff < 4, 'low'], [fn ($r) => $r->staff < 6, 'mid'], default: 'high'),
        volume: if_else(fn ($r) => $r->visitors >= 250, 'busy', 'quiet'),
    )
    |> group_by('staffing', 'volume')
    |> summarise(mean_calls: mean('calls'), _groups: 'drop')
    |> mutate(mean_calls: fn ($r) => round($r->mean_calls, 2))
    |> pivot_wider(names_from: 'volume', values_from: 'mean_calls', names_sort: true)
    |> arrange(fn ($r) => array_search($r->staffing, ['low', 'mid', 'high']));
```

:::

```{.text .output}
# A tibble: 3 × 3
  staffing  busy quiet
  <chr>    <dbl> <dbl>
1 low      NA     1.9 
2 mid       1.79  1.64
3 high      1.8  NA
```
