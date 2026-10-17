```php
$schools = DataFrame::fromCsv('schools.csv')
    |> group_by('school')
    |> summarise(avg_score: mean('score'));

$districts = DataFrame::fromRows([
    ['school_id' => 'school-01', 'district' => 'North'],
    ['school_id' => 'school-02', 'district' => 'North'],
    ['school_id' => 'school-03', 'district' => 'South'],
    ['school_id' => 'school-99', 'district' => 'East'],
]);

echo $schools |> inner_join($districts, by: ['school' => 'school_id']);
echo "\n", $schools |> left_join($districts, by: ['school' => 'school_id']) |> head(5);
echo "\n", $districts |> anti_join($schools, by: ['school_id' => 'school']);
```

```{.text .output}
# A tibble: 3 × 3
  school    avg_score district
  <chr>         <dbl> <chr>   
1 school-01      72.9 North   
2 school-02      72.9 North   
3 school-03      69.0 South   
# A tibble: 5 × 3
  school    avg_score district
  <chr>         <dbl> <chr>   
1 school-01      72.9 North   
2 school-02      72.9 North   
3 school-03      69.0 South   
4 school-04      69.6 <NA>    
5 school-05      73.0 <NA>    
# A tibble: 1 × 2
  school_id district
  <chr>     <chr>   
1 school-99 East
```
