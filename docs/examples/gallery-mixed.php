<?php

// A random-intercept model: students nested in schools. The fixed effects
// are shared; each school gets its own intercept shift.
$schools = DataFrame::fromCsv('schools.csv');
$fit = $schools |> lmer('score ~ study_hours + prior_score + (1 | school)');

foreach ($fit['columns'] as $i => $term) {
    printf("%-12s %8.4f  (se %.4f)\n", $term, $fit['coefficients'][$i], $fit['std_errors'][$i]);
}
$schoolSd = $fit['theta'][0] * $fit['sigma'];
printf("school sd %.3f, residual sd %.3f, ICC %.3f\n\n", $schoolSd, $fit['sigma'], $schoolSd ** 2 / ($schoolSd ** 2 + $fit['sigma'] ** 2));

// Compare each school's raw mean with its estimated intercept shift.
$random = $fit['random'][0];
$effects = array_combine($random['group_labels'], array_column($random['effects'], 0));
echo $schools
    |> group_by('school')
    |> summarise(students: n(), raw_mean: mean('score'))
    |> mutate(intercept_shift: fn ($r) => round($effects[$r->school], 2), raw_mean: fn ($r) => round($r->raw_mean, 1))
    |> arrange(desc('intercept_shift'))
    |> head(5);
