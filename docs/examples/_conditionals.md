```php
echo Frame::fromRows([
    ['state' => 'NC', 'visits' => 3],
    ['state' => 'NYC', 'visits' => null],
    ['state' => 'CA', 'visits' => 12],
    ['state' => null, 'visits' => 0],
])
    |> mutate(
        name: recode_values('state', ['NC', 'North Carolina'], [['NY', 'NYC'], 'New York'], default: 'other'),
        visits: coalesce('visits', 0),
        busy: if_else(fn ($r) => $r->visits > 5, 'yes', 'no'),
        capped: replace_when('visits', [fn ($r) => $r->visits > 10, 10]),
        any_flag: when_any(fn ($r) => $r->state === 'CA', fn ($r) => $r->visits === 0),
    );
```

```{.text .output}
# A tibble: 4 × 6
  state visits name           busy  capped any_flag
  <chr>  <int> <chr>          <chr>  <int> <lgl>   
1 NC         3 North Carolina no         3 FALSE   
2 NYC        0 New York       no         0 TRUE    
3 CA        12 other          yes       10 TRUE    
4 <NA>       0 other          no         0 TRUE
```
