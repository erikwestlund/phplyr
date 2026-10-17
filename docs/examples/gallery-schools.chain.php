<?php

// Which schools do better than their students' prior scores predict?
// Fit one regression on all students, then average each school's residuals.
$schools = DataFrame::fromCsv('schools.csv');
$fit = phplyr($schools)->lm('score ~ prior_score + study_hours');
[$b0, $b_prior, $b_hours] = $fit['coefficients'];

echo phplyr($schools)
    ->mutate(expected: fn ($r) => $b0 + $b_prior * $r->prior_score + $b_hours * $r->study_hours)
    ->mutate(residual: fn ($r) => $r->score - $r->expected)
    ->group_by('school')
    ->summarise(
        students: n(),
        avg_score: mean('score'),
        avg_residual: mean('residual'),
        share_above: fn ($g) => round(\count(array_filter($g->column('residual'), fn ($e) => $e > 0)) / $g->nrow(), 2),
    )
    ->mutate(rank: min_rank(desc('avg_residual')), avg_score: fn ($r) => round($r->avg_score, 1), avg_residual: fn ($r) => round($r->avg_residual, 2))
    ->arrange('rank');
