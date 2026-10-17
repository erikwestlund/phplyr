<?php

$schools = DataFrame::fromCsv('schools.csv');

// Clean: drop incomplete rows, band study time, compute the gain.
$clean = $schools
    |> filter(col('score')->isNa()->not(), col('study_hours')->gt(0))
    |> mutate(
        hours_band: case_when([col('study_hours')->lt(4), 'low'], [col('study_hours')->lt(8), 'mid'], default: 'high'),
        gain: col('score')->sub(col('prior_score')),
        passed: if_else(col('score')->ge(75), 1, 0),
    );

// Reshape: one row per student and test.
$long = $clean
    |> mutate(student: row_number())
    |> select('student', 'school', ['prior' => 'prior_score', 'final' => 'score'])
    |> pivot_longer(['prior', 'final'], names_to: 'test', values_to: 'points');

echo $long |> group_by('test') |> summarise(mean: mean('points'), sd: sd('points')), "\n";

// Summarise: students per school and band, then the schools with the largest gains.
echo $clean
    |> count('school', 'hours_band')
    |> pivot_wider(names_from: 'hours_band', values_from: 'n', values_fill: 0)
    |> head(3), "\n";

echo $clean
    |> group_by('school')
    |> summarise(n: n(), mean_gain: mean('gain'))
    |> arrange(desc('mean_gain'))
    |> head(3), "\n";

// Model: linear, logistic, and random-intercept fits.
$coef = fn (array $fit) => implode('  ', array_map(
    fn ($name, $b) => $name.' '.\round($b, 4),
    $fit['columns'],
    $fit['coefficients'],
));
echo $coef($clean |> lm('score ~ study_hours + prior_score')), "\n";
echo $coef($clean |> glm('passed ~ study_hours + prior_score', family: 'binomial')), "\n";
echo $coef($clean |> lmer('score ~ study_hours + prior_score + (1 | school)')), "\n";
