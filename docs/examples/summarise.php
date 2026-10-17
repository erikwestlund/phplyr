<?php

echo DataFrame::fromCsv('schools.csv')
    |> summarise(
        students: n(),
        schools: n_distinct('school'),
        median_hours: median('study_hours'),
        q90_score: quantile('score', 0.9),
        iqr_score: iqr('score'),
        top_school: fn ($g) => ($g |> slice_max('score', n: 1) |> pull('school'))[0],
    );
