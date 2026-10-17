::: {.panel-tabset group="syntax"}

## Method chain (PHP 8.3+)

```php
$fit = phplyr(DataFrame::fromCsv('schools.csv'))
    ->filter(fn ($r) => $r->prior_score > 50)
    ->lm('score ~ study_hours + prior_score');

foreach (['(Intercept)', 'study_hours', 'prior_score'] as $i => $term) {
    printf("%-12s %8.4f  (se %.4f)\n", $term, $fit['coefficients'][$i], $fit['std_errors'][$i]);
}
printf("R² = %.4f, n = %d\n", $fit['r_squared'], $fit['n_observations']);

$logit = phplyr(DataFrame::fromCsv('learning.csv'))->glm('passed ~ study_hours + practice_score', family: 'binomial');
echo 'logit coefficients: ', implode(', ', array_map(fn ($b) => round($b, 4), $logit['coefficients'])), "\n";
```

## Pipe (PHP 8.5)

```php
$fit = DataFrame::fromCsv('schools.csv')
    |> filter(fn ($r) => $r->prior_score > 50)
    |> lm('score ~ study_hours + prior_score');

foreach (['(Intercept)', 'study_hours', 'prior_score'] as $i => $term) {
    printf("%-12s %8.4f  (se %.4f)\n", $term, $fit['coefficients'][$i], $fit['std_errors'][$i]);
}
printf("R² = %.4f, n = %d\n", $fit['r_squared'], $fit['n_observations']);

$logit = DataFrame::fromCsv('learning.csv') |> glm('passed ~ study_hours + practice_score', family: 'binomial');
echo 'logit coefficients: ', implode(', ', array_map(fn ($b) => round($b, 4), $logit['coefficients'])), "\n";
```

:::

```{.text .output}
(Intercept)   43.4427  (se 3.8669)
study_hours    1.5135  (se 0.2256)
prior_score    0.3074  (se 0.0736)
R² = 0.4914, n = 193
logit coefficients: -3.171, 0.0637, 0.0342
```
