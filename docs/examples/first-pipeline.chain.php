<?php

$schools = DataFrame::fromCsv('schools.csv');

echo phplyr($schools)
    ->filter(fn ($r) => $r->study_hours >= 5)
    ->group_by('school')
    ->summarise(students: n(), avg_score: mean('score'), sd_score: sd('score'))
    ->arrange(desc('avg_score'))
    ->head(5);
