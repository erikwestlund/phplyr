```php
// Pass rates by study-hour quartile, then a logistic regression.
$learning = DataFrame::fromCsv('learning.csv');

echo $learning
    |> mutate(hours_quartile: ntile('study_hours', 4))
    |> group_by('hours_quartile')
    |> summarise(
        students: n(),
        min_hours: min('study_hours'),
        max_hours: max('study_hours'),
        pass_rate: mean('passed'),
    );

$fit = $learning |> glm('passed ~ study_hours + practice_score', family: 'binomial');
printf("\nOdds ratio per extra study hour: %.3f\n", exp($fit['coefficients'][1]));
```

```{.text .output}
# A tibble: 4 × 5
  hours_quartile students min_hours max_hours pass_rate
           <int>    <int>     <dbl>     <dbl>     <dbl>
1              1       20      1.24      3.38      0.25
2              2       20      3.44      5.98      0.3 
3              3       20      6         8.83      0.45
4              4       20      8.87     11.8       0.45
Odds ratio per extra study hour: 1.066
```
