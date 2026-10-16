<?php

$schools = Frame::fromCsv('schools.csv')
    |> mutate(band: case_when(
        [fn ($r) => $r->study_hours < 4, 'light'],
        [fn ($r) => $r->study_hours < 8, 'moderate'],
        default: 'heavy',
    ));

echo $schools |> count('band', sort: true);
echo "\n", $schools |> count('band', wt: 'study_hours', name: 'hours');
