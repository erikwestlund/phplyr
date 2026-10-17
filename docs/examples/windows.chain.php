<?php

echo phplyr(DataFrame::fromCsv('schools.csv'))
    ->filter(fn ($r) => $r->school === 'school-01')
    ->arrange('study_hours')
    ->mutate(
        prev: lag('score'),
        change: lag('score')->map(fn ($prev, $r) => $prev === null ? null : round($r->score - $prev, 2)),
        running_mean: cummean('score'),
        rank: min_rank(desc('score')),
        pct: percent_rank('score'),
        quartile: ntile('score', 4),
    )
    ->select('study_hours', 'score', 'prev', 'change', 'running_mean', 'rank', 'pct', 'quartile')
    ->head(8);
