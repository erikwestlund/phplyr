<?php

// Pass rates by study-hour quartile, then a logistic regression.
$learning = Frame::fromCsv('learning.csv');

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
