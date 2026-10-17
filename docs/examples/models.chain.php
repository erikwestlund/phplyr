<?php

$fit = phplyr(DataFrame::fromCsv('schools.csv'))
    ->filter(fn ($r) => $r->prior_score > 50)
    ->lm('score ~ study_hours + prior_score');

foreach (['(Intercept)', 'study_hours', 'prior_score'] as $i => $term) {
    printf("%-12s %8.4f  (se %.4f)\n", $term, $fit['coefficients'][$i], $fit['std_errors'][$i]);
}
printf("R² = %.4f, n = %d\n", $fit['r_squared'], $fit['n_observations']);

$logit = phplyr(DataFrame::fromCsv('learning.csv'))->glm('passed ~ study_hours + practice_score', family: 'binomial');
echo 'logit coefficients: ', implode(', ', array_map(fn ($b) => round($b, 4), $logit['coefficients'])), "\n";
