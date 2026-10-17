<?php

$schools = phplyr(DataFrame::fromCsv('schools.csv'))
    ->mutate(band: case_when(
        [fn ($r) => $r->study_hours < 4, 'light'],
        [fn ($r) => $r->study_hours < 8, 'moderate'],
        default: 'heavy',
    ))
    ->get();

echo phplyr($schools)->count('band', sort: true);
echo "\n", phplyr($schools)->count('band', wt: 'study_hours', name: 'hours');
