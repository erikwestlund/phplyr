```php
$schools = DataFrame::fromCsv('schools.csv') |> group_by('school');

echo json_encode([
    'groups' => $schools |> group_vars(),
    'n_groups' => $schools |> n_groups(),
    'sizes' => \array_slice($schools |> group_size(), 0, 4),
]), "\n\n";

// Grouped mutate: helpers are evaluated within each group.
echo $schools
    |> mutate(centered: fn ($r, $g) => round($r->score - mean('score')($g), 2), rank_in_school: min_rank(desc('score')))
    |> ungroup()
    |> head(4);

// One table per group.
$parts = $schools |> group_split();
echo "\n", \count($parts), ' tables; the first has ', $parts[0]->nrow(), " rows\n";
```

```{.text .output}
{"groups":["school"],"n_groups":12,"sizes":[18,24,15,22]}

# A tibble: 4 × 6
  school    study_hours prior_score score centered rank_in_school
  <chr>           <dbl>       <dbl> <dbl>    <dbl>          <int>
1 school-01       11.6         77.3  78.3     5.34              4
2 school-01        2.7         51.7  67.2    -5.67             14
3 school-01       10.0         78.8  88.2    15.3               2
4 school-01        3.71        45.3  68.5    -4.37             13
12 tables; the first has 18 rows
```
